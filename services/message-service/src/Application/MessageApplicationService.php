<?php

declare(strict_types=1);

namespace Services\MessageService\Application;

use Contracts\Gen\Common\V1\IdentityMode;
use Contracts\Gen\Common\V1\PublicAuthor;
use Contracts\Gen\Message\V1\PublicMessage;
use Contracts\Events\EventTypes;
use Google\Protobuf\PrintOptions;
use JsonException;
use Packages\Identity\AnonymousAuthorProjector;
use Packages\Identity\AnonymousReferenceFactory;
use Packages\GrpcCommon\OperationBudget;
use Packages\Tracing\TraceContext;
use Services\MessageService\Domain\ChatMemberSnapshotClient;
use Services\MessageService\Domain\Exception\MessageException;
use Services\MessageService\Domain\IdGenerator;
use Services\MessageService\Domain\Repository\MessageRepositoryInterface;
use Services\MessageService\Domain\Repository\MessageTransactionManagerInterface;
use Services\MessageService\Domain\Repository\MessageWriteTransactionInterface;
use Services\MessageService\Infrastructure\RandomIdGenerator;
use Throwable;

final class MessageApplicationService
{
    private const MAIN = 1;
    private const ALIAS = 2;

    public function __construct(
        private readonly MessageRepositoryInterface $repository,
        private readonly MessageTransactionManagerInterface $transactions,
        private readonly ChatMemberSnapshotClient $chat,
        ?IdGenerator $ids = null,
        ?\Closure $clock = null,
        ?AnonymousAuthorProjector $projector = null,
    ) {
        $this->ids = $ids ?? new RandomIdGenerator();
        $this->clock = $clock ?? static fn (): string => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        if ($projector !== null) {
            $this->projector = $projector;
        } else {
            $key = trim((string)getenv('ANONYMOUS_IDENTITY_KEY'));
            if ($key === '') {
                throw new \RuntimeException('ANONYMOUS_IDENTITY_KEY is required');
            }
            $this->projector = new AnonymousAuthorProjector(new AnonymousReferenceFactory($key));
        }
    }

    private readonly IdGenerator $ids;
    private readonly AnonymousAuthorProjector $projector;
    /** @var \Closure():string */
    private readonly \Closure $clock;

    /** @param array<string,mixed> $request */
    public function sendMessage(string $trustedUserId, array $request, ?OperationBudget $budget = null): PublicMessage
    {
        $budget ??= new OperationBudget(600);
        $trustedUserId = $this->id($trustedUserId, 'user_id');
        $clientMessageId = trim((string)($request['client_message_id'] ?? ''));
        if ($clientMessageId === '' || strlen($clientMessageId) > 36) {
            throw MessageException::invalid('client_message_id is required and must be at most 36 bytes');
        }
        $conversationId = $this->id((string)($request['conversation_id'] ?? ''), 'conversation_id');
        $type = (int)($request['type'] ?? 0);
        if ($type <= 0) {
            throw MessageException::invalid('message type is required');
        }
        $content = (string)($request['content'] ?? '');
        if ($content === '' || strlen($content) > 65535) {
            throw MessageException::invalid('message content is required or too long');
        }
        try {
            json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw MessageException::invalid('message content must be valid JSON');
        }

        $access = $this->chat->getConversationMemberSnapshot($trustedUserId, $conversationId, $budget);
        $member = $this->authorizedMember($access, $trustedUserId, $conversationId);
        $normalized = [
            'client_message_id' => $clientMessageId,
            'conversation_id' => $conversationId,
            'type' => $type,
            'content' => $content,
        ];
        $existing = $this->repository->findBySenderClientId($trustedUserId, $clientMessageId, $budget);
        if ($existing !== null) {
            if (!$this->repository->idempotencyMatches($existing, $normalized)) {
                throw MessageException::duplicate('client_message_id was already used for a different message');
            }

            return $this->publicMessage($existing);
        }

        // Values outside the transaction callback stay stable if MongoDB asks
        // us to retry a transient transaction.
        $now = ($this->clock)();
        $messageId = $this->ids->nextId();
        $eventId = TraceContext::generateUuidV7();
        $traceId = TraceContext::current()->traceId;
        try {
            return $this->transactions->transaction(function (MessageWriteTransactionInterface $transaction) use (
                $trustedUserId,
                $clientMessageId,
                $conversationId,
                $type,
                $content,
                $member,
                $normalized,
                $now,
                $messageId,
                $eventId,
                $traceId,
            ): PublicMessage {
                $existing = $transaction->findBySenderClientId($trustedUserId, $clientMessageId);
                if ($existing !== null) {
                    if (!$this->repository->idempotencyMatches($existing, $normalized)) {
                        throw MessageException::duplicate('client_message_id was already used for a different message');
                    }
                    return $this->publicMessage($existing);
                }

                $row = [
                    'message_id' => $messageId,
                    'conversation_id' => $conversationId,
                    'seq' => $transaction->nextSequence($conversationId, $now),
                    'sender_user_id' => $trustedUserId,
                    'sender_persona_id' => (string)$member['persona_id'],
                    'sender_identity_mode' => (int)$member['identity_mode'],
                    'sender_display_name' => $member['display_name_snapshot'] ?? $member['display_name'] ?? null,
                    'sender_avatar_key' => $member['avatar_key_snapshot'] ?? $member['avatar_key'] ?? null,
                    'sender_anonymous_ref' => $member['anonymous_ref'] ?? null,
                    'sender_scope_type' => $member['scope_type'] ?? null,
                    'sender_scope_id' => $member['scope_id'] ?? null,
                    'client_message_id' => $clientMessageId,
                    'type' => $type,
                    'content' => $content,
                    'created_at' => $now,
                ];
                $transaction->insert($row);
                $message = $this->publicMessage($row);
                $payload = json_decode($message->serializeToJsonString(
                    PrintOptions::PRESERVE_PROTO_FIELD_NAMES | PrintOptions::ALWAYS_PRINT_ENUMS_AS_INTS,
                ), true, 512, JSON_THROW_ON_ERROR);
                $transaction->recordMessageCreated(
                    $eventId,
                    $conversationId,
                    $messageId,
                    (string)$row['seq'],
                    ['message' => $payload],
                    $traceId,
                    $now,
                );
                return $message;
            }, $budget);
        } catch (Throwable $exception) {
            if (!$this->isDuplicateKey($exception)) {
                throw $exception;
            }
            // A sender/client unique-key race is only idempotent when the
            // winning row is found by that business key. An _id collision is
            // deliberately allowed to propagate as an internal error.
            $existing = $this->repository->findBySenderClientId($trustedUserId, $clientMessageId, $budget);
            if ($existing === null) {
                throw $exception;
            }
            if (!$this->repository->idempotencyMatches($existing, $normalized)) {
                throw MessageException::duplicate('client_message_id was already used for a different message');
            }
            return $this->publicMessage($existing);
        }
    }

    /** @return array{messages:list<PublicMessage>,has_more:bool,latest_seq:string} */
    public function syncMessages(
        string $trustedUserId,
        string $conversationId,
        string $afterSeq,
        int $limit,
        ?OperationBudget $budget = null,
    ): array
    {
        $budget ??= new OperationBudget(600);
        $trustedUserId = $this->id($trustedUserId, 'user_id');
        $conversationId = $this->id($conversationId, 'conversation_id');
        $afterSeq = $this->uint($afterSeq, 'after_seq');
        $limit = $this->limit($limit);
        $this->authorizedMember(
            $this->chat->getConversationMemberSnapshot($trustedUserId, $conversationId, $budget),
            $trustedUserId,
            $conversationId,
        );
        $rows = $this->repository->listAfter($conversationId, $afterSeq, $limit, $budget);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        return [
            'messages' => array_map(fn (array $row): PublicMessage => $this->publicMessage($row), $rows),
            'has_more' => $hasMore,
            'latest_seq' => $this->repository->latestSequence($conversationId, $budget),
        ];
    }

    /** @return array{messages:list<PublicMessage>,has_more:bool,next_cursor:string,total:string} */
    public function getMessages(
        string $trustedUserId,
        string $conversationId,
        int $limit,
        ?string $cursor,
        ?OperationBudget $budget = null,
    ): array
    {
        $budget ??= new OperationBudget(600);
        $trustedUserId = $this->id($trustedUserId, 'user_id');
        $conversationId = $this->id($conversationId, 'conversation_id');
        $limit = $this->limit($limit);
        $this->authorizedMember(
            $this->chat->getConversationMemberSnapshot($trustedUserId, $conversationId, $budget),
            $trustedUserId,
            $conversationId,
        );
        if ($cursor !== null && $cursor !== '') {
            $cursor = $this->id($cursor, 'cursor');
        }
        $rows = $this->repository->listPage($conversationId, $limit, $cursor, $budget);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        return [
            'messages' => array_map(fn (array $row): PublicMessage => $this->publicMessage($row), $rows),
            'has_more' => $hasMore,
            'next_cursor' => $hasMore && $rows !== [] ? (string)$rows[array_key_last($rows)]['message_id'] : '',
            'total' => $this->repository->countForConversation($conversationId, $budget),
        ];
    }

    /**
     * @param array<string,mixed> $access
     * @return array<string,mixed>
     */
    private function authorizedMember(array $access, string $trustedUserId, string $conversationId): array
    {
        if (($access['allowed'] ?? false) !== true) {
            throw MessageException::forbidden((string)($access['denial_reason'] ?? 'conversation access denied'));
        }
        $member = $access['member'] ?? null;
        if (!is_array($member) || (string)($member['user_id'] ?? '') !== $trustedUserId) {
            throw MessageException::forbidden('conversation membership is invalid');
        }
        $mode = (int)($member['identity_mode'] ?? 0);
        if (!in_array($mode, [self::MAIN, self::ALIAS], true)) {
            throw MessageException::forbidden('conversation identity snapshot is invalid');
        }
        if ($mode === self::MAIN) {
            if (preg_match('/\A[1-9][0-9]*\z/', (string)($member['persona_id'] ?? '')) !== 1) {
                throw MessageException::forbidden('conversation identity snapshot is invalid');
            }
        } elseif ((string)($member['scope_type'] ?? '') !== 'conversation'
            || (string)($member['scope_id'] ?? '') !== $conversationId
            || preg_match('/\Aanon_[A-Za-z0-9_-]{1,120}\z/', (string)($member['anonymous_ref'] ?? '')) !== 1
        ) {
            throw MessageException::forbidden('conversation identity snapshot is invalid');
        }

        return $member;
    }

    /** @param array<string,mixed> $row */
    private function publicMessage(array $row): PublicMessage
    {
        $projected = $this->projector->project(
            [
                'identity_mode' => $row['sender_identity_mode'] ?? null,
                'persona_id' => $row['sender_persona_id'] ?? null,
                'display_name' => $row['sender_display_name'] ?? null,
                'avatar_key' => $row['sender_avatar_key'] ?? null,
                'anonymous_ref' => $row['sender_anonymous_ref'] ?? null,
                'scope_type' => $row['sender_scope_type'] ?? null,
                'scope_id' => $row['sender_scope_id'] ?? null,
            ],
            'conversation',
            (string)$row['conversation_id'],
        );
        $alias = ($projected['identity_mode'] ?? 'alias') !== 'main';
        $author = new PublicAuthor([
            'identity_mode' => $alias ? IdentityMode::IDENTITY_MODE_ALIAS : IdentityMode::IDENTITY_MODE_MAIN,
            'persona_id' => $alias ? '0' : (string)($projected['persona_id'] ?? '0'),
            'display_name' => (string)($projected['display_name'] ?? ($alias ? 'Anonymous user' : 'User')),
            'avatar_key' => (string)($projected['avatar_key'] ?? ($alias ? 'system:anonymous' : '')),
            'anonymous_ref' => $alias ? (string)($projected['anonymous_ref'] ?? '') : '',
            'profile_accessible' => (bool)($projected['profile_accessible'] ?? false),
        ]);

        return new PublicMessage([
            'message_id' => (string)$row['message_id'],
            'conversation_id' => (string)$row['conversation_id'],
            'seq' => (string)$row['seq'],
            'client_message_id' => (string)$row['client_message_id'],
            'type' => (int)$row['type'],
            'content' => (string)$row['content'],
            'created_at' => (string)$row['created_at'],
            'author' => $author,
        ]);
    }

    private function id(int|string $value, string $field): string
    {
        $value = trim((string)$value);
        if ($value === '' || $value === '0' || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1
            || strlen($value) > 19 || (strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0)) {
            throw MessageException::invalid($field . ' is required');
        }

        return $value;
    }

    private function uint(int|string $value, string $field): string
    {
        $value = trim((string)$value);
        if (preg_match('/\A[0-9]+\z/', $value) !== 1 || strlen($value) > 19
            || (strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0)) {
            throw MessageException::invalid($field . ' is invalid');
        }

        return $value;
    }

    private function limit(int $limit): int
    {
        return max(1, min($limit > 0 ? $limit : 50, 100));
    }

    private function isDuplicateKey(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ((int)$current->getCode() === 11000) {
                return true;
            }
        }
        return false;
    }
}
