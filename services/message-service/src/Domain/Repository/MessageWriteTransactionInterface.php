<?php

declare(strict_types=1);

namespace Services\MessageService\Domain\Repository;

interface MessageWriteTransactionInterface
{
    /** @return array<string,mixed>|null */
    public function findBySenderClientId(string $senderUserId, string $clientMessageId): ?array;

    public function nextSequence(string $conversationId, string $now): string;

    /** @param array<string,mixed> $message */
    public function insert(array $message): void;

    /** @param array<string,mixed> $payload */
    public function recordMessageCreated(
        string $eventId,
        string $conversationId,
        string $messageId,
        string $sequence,
        array $payload,
        string $traceId,
        string $now,
    ): void;
}
