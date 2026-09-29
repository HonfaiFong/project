<?php

declare(strict_types=1);

namespace Packages\Outbox;

interface EventOutboxInterface
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
    ): string;
}
