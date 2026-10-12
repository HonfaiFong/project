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
use Packages\Outbox\EventOutboxInterface;
use Packages\Outbox\OutboxRepository;
use Packages\Tracing\TraceContext;
use Services\MessageService\Domain\ChatMemberSnapshotClient;
use Services\MessageService\Domain\Exception\MessageException;
use Services\MessageService\Domain\IdGenerator;
use Services\MessageService\Infrastructure\PdoMessageRepository;
use Services\MessageService\Infrastructure\RandomIdGenerator;
use Throwable;

final class MessageApplicationService
{
    private const MAIN = 1;
    private const ALIAS = 2;

    public function __construct(
        private readonly PdoMessageRepository $repository,
        private readonly ChatMemberSnapshotClient $chat,
        ?IdGenerator $ids = null,
        ?\Closure $clock = null,
        ?AnonymousAuthorProjector $projector = null,
        ?EventOutboxInterface $outbox = null,
    ) {
        $this->outbox = $outbox ?? new OutboxRepository($repository->pdo());
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
    private readonly EventOutboxInterface $outbox;
    private readonly AnonymousAuthorProjector $projector;
    /** @var \Closure():string */
    private readonly \Closure $clock;

    /** @param array<string,mixed> $request */
    public function sendMessage(string $trustedUserId, array $request): PublicMessage
    {
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

        $access = $this->chat->getConversationMemberSnapshot($trustedUserId, $conversationId);
        $member = $this->authorizedMember($access, $trustedUserId, $conversationId);
        $normalized = [
            'client_message_id' => $clientMessageId,
            'conversation_id' => $conversationId,
            'type' => $type,
            'content' => $content,
        ];
        $existing = $this->repository->findBySenderClientId($trustedUserId, $clientMessageId);
        if ($existing !== null) {
            if (!$this->repository->idempotencyMatches($existing, $normalized)) {
                throw MessageException::duplicate('client_message_id was already used for a different message');
            }

            return $this->publicMessage($existing);
        }

        $pdo = $this->repository->pdo();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $pdo->beginTransaction();
                $existing = $this->repository->findBySenderClientId($trustedUserId, $clientMessageId);
                if ($existing !== null) {
                    $pdo->commit();
                    if (!$this->repository->idempotencyMatches($existing, $normalized)) {
                        throw MessageException::duplicate('client_message_id was already used for a different message');
                    }

                    return $this->publicMessage($existing);
                }
                $now = ($this->clock)();
                $seq = $this->repository->nextSequence($conversationId, $now);
                $row = [
                    'message_id' => $this->ids->nextId(),
                    'conversation_id' => $conversationId,
                    'seq' => $seq,
                    'sender_user_id' => $trustedUserId,
                    // This is from ChatService's immutable member snapshot,
                    // never from the public SendMessage body.
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
                $this->repository->insert($row);
                $publicMessage = $this->publicMessage($row);
                $this->outbox->recordEvent(
                    eventId: TraceContext::generateUuidV7(),
                    aggregateType: 'conversation',
                    aggregateId: $conversationId,
                    eventType: EventTypes::MESSAGE_CREATED,
                    eventVersion: 1,
                    payload: [
                        'message' => json_decode($publicMessage->serializeToJsonString(
                            PrintOptions::PRESERVE_PROTO_FIELD_NAMES | PrintOptions::ALWAYS_PRINT_ENUMS_AS_INTS,
                        ), true, 512, JSON_THROW_ON_ERROR),
                    ],
                    eventKey: $conversationId,
                    traceId: TraceContext::current()->traceId,
                );
                $pdo->commit();

                return $publicMessage;
            } catch (MessageException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $existing = $this->repository->findBySenderClientId($trustedUserId, $clientMessageId);
                if ($existing !== null) {
                    if (!$this->repository->idempotencyMatches($existing, $normalized)) {
                        throw MessageException::duplicate('client_message_id was already used for a different message');
                    }

                    return $this->publicMessage($existing);
                }
                if ($attempt === 2 || !$this->isRetryableConflict($e)) {
                    throw $e;
                }
            }
        }

        throw MessageException::forbidden('message could not be persisted');
    }

    /** @return array{messages:list<PublicMessage>,has_more:bool,latest_seq:string} */
    public function syncMessages(string $trustedUserId, string $conversationId, string $afterSeq, int $limit): array
    {
        $trustedUserId = $this->id($trustedUserId, 'user_id');
        $conversationId = $this->id($conversationId, 'conversation_id');
        $afterSeq = $this->uint($afterSeq, 'after_seq');
        $limit = $this->limit($limit);
        $this->authorizedMember(
            $this->chat->getConversationMemberSnapshot($trustedUserId, $conversationId),
            $trustedUserId,
            $conversationId,
        );
        $rows = $this->repository->listAfter($conversationId, $afterSeq, $limit);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        return [
            'messages' => array_map(fn (array $row): PublicMessage => $this->publicMessage($row), $rows),
            'has_more' => $hasMore,
            'latest_seq' => $this->repository->latestSequence($conversationId),
        ];
    }

    /** @return array{messages:list<PublicMessage>,has_more:bool,next_cursor:string,total:string} */
    public function getMessages(string $trustedUserId, string $conversationId, int $limit, ?string $cursor): array
    {
        $trustedUserId = $this->id($trustedUserId, 'user_id');
        $conversationId = $this->id($conversationId, 'conversation_id');
        $limit = $this->limit($limit);
        $this->authorizedMember(
            $this->chat->getConversationMemberSnapshot($trustedUserId, $conversationId),
            $trustedUserId,
            $conversationId,
        );
        if ($cursor !== null && $cursor !== '') {
            $cursor = $this->id($cursor, 'cursor');
        }
        $rows = $this->repository->listPage($conversationId, $limit, $cursor);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        return [
            'messages' => array_map(fn (array $row): PublicMessage => $this->publicMessage($row), $rows),
            'has_more' => $hasMore,
            'next_cursor' => $hasMore && $rows !== [] ? (string)$rows[array_key_last($rows)]['message_id'] : '',
            'total' => $this->countForConversation($conversationId),
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

    private function countForConversation(string $conversationId): string
    {
        $statement = $this->repository->pdo()->prepare('SELECT COUNT(*) FROM messages WHERE conversation_id = :conversation_id');
        $statement->execute(['conversation_id' => $conversationId]);

        return (string)$statement->fetchColumn();
    }

    private function id(int|string $value, string $field): string
    {
        $value = trim((string)$value);
        if ($value === '' || $value === '0' || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw MessageException::invalid($field . ' is required');
        }

        return $value;
    }

    private function uint(int|string $value, string $field): string
    {
        $value = trim((string)$value);
        if (preg_match('/\A[0-9]+\z/', $value) !== 1) {
            throw MessageException::invalid($field . ' is invalid');
        }

        return $value;
    }

    private function limit(int $limit): int
    {
        return max(1, min($limit > 0 ? $limit : 50, 100));
    }

    private function isRetryableConflict(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || str_contains($message, 'locked');
    }
}
