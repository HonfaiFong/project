<?php

declare(strict_types=1);

namespace Services\ChatService\Infrastructure;

use Redis;
use RuntimeException;
use Services\ChatService\Domain\RealtimeMessagePublisher;

final readonly class RedisRealtimeMessagePublisher implements RealtimeMessagePublisher
{
    public function __construct(private Redis $redis)
    {
    }

    public function publish(string $eventId, array $recipients, array $message): void
    {
        $result = $this->redis->publish('realtime:message.created', json_encode([
            'event_id' => $eventId, 'recipients' => $recipients, 'message' => $message,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($result === false) {
            throw new RuntimeException('Realtime message publication failed');
        }
    }
}
