<?php

declare(strict_types=1);

namespace Services\ChatService\Domain;

interface RealtimeMessagePublisher
{
    /** @param list<string> $recipients @param array<string, mixed> $message */
    public function publish(string $eventId, array $recipients, array $message): void;
}
