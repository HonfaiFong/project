<?php

declare(strict_types=1);

namespace Packages\Outbox;

use Throwable;

final readonly class OutboxRelay
{
    public function __construct(
        private OutboxRelayStore $outbox,
        private EventPublisher $publisher,
    ) {
    }

    /** @throws Throwable */
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
