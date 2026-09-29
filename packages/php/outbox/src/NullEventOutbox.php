<?php

declare(strict_types=1);

namespace Packages\Outbox;

final class NullEventOutbox implements EventOutboxInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function recordEvent(
        string $eventId,
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        int $eventVersion,
        array $payload
    ): string {
        return $eventId;
    }
}
