<?php

declare(strict_types=1);

namespace Tests\Support;

use Packages\GrpcCommon\OperationBudget;
use Services\MessageService\Application\MessageContentCodec;
use Services\MessageService\Domain\Repository\MessageRepositoryInterface;
use Services\MessageService\Domain\Repository\MessageTransactionManagerInterface;
use Services\MessageService\Domain\Repository\MessageWriteTransactionInterface;
use Throwable;

final class InMemoryMessageStore implements MessageRepositoryInterface, MessageTransactionManagerInterface, MessageWriteTransactionInterface
{
    /** @var list<array<string,mixed>> */
    public array $messages = [];

    /** @var array<string,int> */
    public array $sequences = [];

    /** @var list<array<string,mixed>> */
    public array $outbox = [];

    public bool $failOutbox = false;

    private readonly MessageContentCodec $codec;

    public function __construct()
    {
        $this->codec = new MessageContentCodec();
    }

    public function findBySenderClientId(string $senderUserId, string $clientMessageId, ?OperationBudget $budget = null): ?array
    {
        foreach ($this->messages as $msg) {
            if ((string)$msg['sender_user_id'] === $senderUserId && (string)$msg['client_message_id'] === $clientMessageId) {
                return $msg;
            }
        }
        return null;
    }

    public function idempotencyMatches(array $existing, array $request, ?OperationBudget $budget = null): bool
    {
        $existingContent = $this->codec->canonicalJson($existing['content'] ?? null);
        $requestedContent = $this->codec->canonicalJson($request['content'] ?? null);
        return (string)$existing['conversation_id'] === (string)$request['conversation_id']
            && (int)$existing['type'] === (int)$request['type']
            && (($existingContent !== null && $requestedContent !== null)
                ? $existingContent === $requestedContent
                : (string)$existing['content'] === (string)$request['content']);
    }

    public function listAfter(string $conversationId, string $afterSeq, int $limit, ?OperationBudget $budget = null): array
    {
        $threshold = (int)$afterSeq;
        $matched = array_values(array_filter($this->messages, static function (array $msg) use ($conversationId, $threshold): bool {
            return (string)$msg['conversation_id'] === $conversationId && (int)$msg['seq'] > $threshold;
        }));
        usort($matched, static fn (array $a, array $b): int => (int)$a['seq'] <=> (int)$b['seq']);
        return array_slice($matched, 0, $limit + 1);
    }

    public function listPage(string $conversationId, int $limit, ?string $cursor, ?OperationBudget $budget = null): array
    {
        $cursorSeq = $cursor !== null ? self::normalizeUnsigned($cursor) : null;
        $matched = array_values(array_filter($this->messages, static function (array $msg) use ($conversationId, $cursorSeq): bool {
            if ((string)$msg['conversation_id'] !== $conversationId) {
                return false;
            }
            return $cursorSeq === null || self::compareUnsigned((string)$msg['seq'], $cursorSeq) < 0;
        }));
        usort($matched, static fn (array $a, array $b): int => self::compareUnsigned((string)$b['seq'], (string)$a['seq']));
        return array_slice($matched, 0, $limit + 1);
    }

    public function latestSequence(string $conversationId, ?OperationBudget $budget = null): string
    {
        return (string)($this->sequences[$conversationId] ?? 0);
    }

    /**
     * @template T
     * @param callable(MessageWriteTransactionInterface): T $operation
     * @param ?OperationBudget $budget
     * @return T
     */
    public function transaction(callable $operation, ?OperationBudget $budget = null): mixed
    {
        $savedMessages = $this->messages;
        $savedSequences = $this->sequences;
        $savedOutbox = $this->outbox;

        try {
            return $operation($this);
        } catch (Throwable $e) {
            $this->messages = $savedMessages;
            $this->sequences = $savedSequences;
            $this->outbox = $savedOutbox;
            throw $e;
        }
    }

    public function nextSequence(string $conversationId, string $now): string
    {
        $next = ($this->sequences[$conversationId] ?? 0) + 1;
        $this->sequences[$conversationId] = $next;
        return (string)$next;
    }

    public function insert(array $message): void
    {
        $this->messages[] = $message;
    }

    public function recordMessageCreated(
        string $eventId,
        string $conversationId,
        string $messageId,
        string $sequence,
        array $payload,
        string $traceId,
        string $now,
    ): void {
        if ($this->failOutbox) {
            throw new \RuntimeException('outbox unavailable');
        }
        $this->outbox[] = [
            'event_id' => $eventId,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'sequence' => $sequence,
            'payload' => $payload,
            'trace_id' => $traceId,
            'created_at' => $now,
        ];
    }

    /** @param array<string,mixed> $changes */
    public function updateMessage(string $messageId, array $changes): void
    {
        foreach ($this->messages as $key => $msg) {
            if ((string)$msg['message_id'] === $messageId) {
                $this->messages[$key] = array_merge($msg, $changes);
                return;
            }
        }
    }

    private static function compareUnsigned(string $left, string $right): int
    {
        $left = self::normalizeUnsigned($left);
        $right = self::normalizeUnsigned($right);
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private static function normalizeUnsigned(string $value): string
    {
        return ltrim($value, '0') ?: '0';
    }
}
