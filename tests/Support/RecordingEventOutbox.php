<?php

declare(strict_types=1);

namespace Tests\Support;

use Packages\Outbox\EventOutboxInterface;

final class RecordingEventOutbox implements EventOutboxInterface
{
    /**
     * @var array<int, array{
     *     event_id: string,
     *     aggregate_type: string,
     *     aggregate_id: string,
     *     event_key: string,
     *     trace_id: string,
     *     event_type: string,
     *     event_version: int,
     *     payload: array<string, mixed>
     * }>
     */
    public array $recordedEvents = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function recordEvent(
        string $eventId,
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        int $eventVersion,
        array $payload,
        string $eventKey,
        string $traceId
    ): string {
        $this->recordedEvents[] = [
            'event_id'       => $eventId,
            'aggregate_type' => $aggregateType,
            'aggregate_id'   => $aggregateId,
            'event_key'      => $eventKey,
            'trace_id'       => $traceId,
            'event_type'     => $eventType,
            'event_version'  => $eventVersion,
            'payload'        => $payload,
        ];

        return $eventId;
    }

    /**
     * Helper to find recorded events by event_type.
     *
     * @return array<int, array{
     *     event_id: string,
     *     aggregate_type: string,
     *     aggregate_id: string,
     *     event_key: string,
     *     trace_id: string,
     *     event_type: string,
     *     event_version: int,
     *     payload: array<string, mixed>
     * }>
     */
    public function findByEventType(string $eventType): array
    {
        return array_values(array_filter(
            $this->recordedEvents,
            static fn (array $event): bool => $event['event_type'] === $eventType
        ));
    }
}
