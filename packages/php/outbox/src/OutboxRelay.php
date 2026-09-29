<?php

declare(strict_types=1);

namespace Packages\Outbox;

use Throwable;

final class OutboxRelay
{
    public function __construct(
        private readonly OutboxRelayStore $outbox,
        private readonly EventPublisher $publisher,
    ) {
    }

    public function runOnce(): int
    {
        $this->outbox->releaseStaleClaims();
        $events = $this->outbox->claimPending(1);
        foreach ($events as $event) {
            try {
                $this->publisher->publish($event);
                $this->outbox->markPublished((string)$event->id);
            } catch (Throwable $exception) {
                $this->outbox->markFailed((string)$event->id);
                throw $exception;
            }
        }

        return count($events);
    }
}
