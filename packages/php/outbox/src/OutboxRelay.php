<?php

declare(strict_types=1);

namespace Packages\Outbox;

use Packages\Observability\MetricsRegistry;
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
        $released = $this->outbox->releaseStaleClaims();
        if ($released > 0) {
            MetricsRegistry::instance()->inc('outbox_stale_claims_released_total', [], (float)$released);
        }
        $events = $this->outbox->claimPending(1);
        MetricsRegistry::instance()->inc('outbox_events_claimed_total', [], (float)count($events));
        foreach ($events as $claim) {
            try {
                $this->publisher->publish($claim->record);
                if ($this->outbox->markPublished($claim)) {
                    MetricsRegistry::instance()->inc('outbox_events_published_total');
                } else {
                    MetricsRegistry::instance()->inc('outbox_claims_lost_total');
                }
            } catch (Throwable $exception) {
                if ($this->outbox->markFailed($claim)) {
                    MetricsRegistry::instance()->inc('outbox_publish_failures_total');
                } else {
                    MetricsRegistry::instance()->inc('outbox_claims_lost_total');
                }
                throw $exception;
            }
        }

        if (method_exists($this->outbox, 'backlogDepth')) {
            try {
                /** @var callable():int $backlogDepth */
                $backlogDepth = [$this->outbox, 'backlogDepth'];
                MetricsRegistry::instance()->set('outbox_backlog', [], (float)$backlogDepth());
            } catch (Throwable) {
                MetricsRegistry::instance()->inc('outbox_backlog_read_failures_total');
            }
        }

        return count($events);
    }
}
