<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Events\EventEnvelope;
use Contracts\Events\EventTypes;
use Tests\TestCase;

final class EventEnvelopeTest extends TestCase
{
    public function testSerializationAndDeserialization(): void
    {
        $envelope = new EventEnvelope(
            eventId: '018f0a00-1234-7000-8000-000000000001',
            eventType: EventTypes::MESSAGE_CREATED,
            eventVersion: 1,
            occurredAt: '2026-09-28T08:00:00Z',
            traceId: 'trace-12345678',
            producer: 'message-service',
            key: 'conv-999',
            payload: ['content' => 'hello world']
        );

        $json = $envelope->toJson();
        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $restored = EventEnvelope::fromArray($decoded);

        $this->assertSame($envelope->eventId, $restored->eventId);
        $this->assertSame($envelope->eventType, $restored->eventType);
        $this->assertSame($envelope->producer, $restored->producer);
        $this->assertSame('hello world', $restored->payload['content']);
    }
}
