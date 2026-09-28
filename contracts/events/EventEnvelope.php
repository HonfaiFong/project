<?php

declare(strict_types=1);

namespace Contracts\Events;

final readonly class EventEnvelope
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $eventId,
        public string $eventType,
        public int $eventVersion,
        public string $occurredAt,
        public string $traceId,
        public string $producer,
        public string $key,
        public array $payload,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id'      => $this->eventId,
            'event_type'    => $this->eventType,
            'event_version' => $this->eventVersion,
            'occurred_at'   => $this->occurredAt,
            'trace_id'      => $this->traceId,
            'producer'      => $this->producer,
            'key'           => $this->key,
            'payload'       => $this->payload,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            eventId: (string)($data['event_id'] ?? ''),
            eventType: (string)($data['event_type'] ?? ''),
            eventVersion: (int)($data['event_version'] ?? 1),
            occurredAt: (string)($data['occurred_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
            traceId: (string)($data['trace_id'] ?? ''),
            producer: (string)($data['producer'] ?? ''),
            key: (string)($data['key'] ?? ''),
            payload: (array)($data['payload'] ?? []),
        );
    }
}
