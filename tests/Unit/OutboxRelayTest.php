<?php

declare(strict_types=1);

namespace Tests\Unit;

use Packages\Outbox\EventPublisher;
use Packages\Outbox\OutboxRecord;
use Packages\Outbox\OutboxRelay;
use Packages\Outbox\OutboxRelayStore;
use Packages\Outbox\OutboxClaim;
use RuntimeException;
use Tests\TestCase;

final class OutboxRelayTest extends TestCase
{
    public function testAckMarksClaimedEventPublished(): void
    {
        $store = $this->store();
        $publisher = new class implements EventPublisher {
            public function publish(OutboxRecord $event): void {}
        };

        $this->assertSame(1, (new OutboxRelay($store, $publisher))->runOnce());
        $this->assertSame(['event-1'], $store->published);
        $this->assertSame([], $store->failed);
    }

    public function testPublisherFailureSchedulesRetryWithoutPublishing(): void
    {
        $store = $this->store();
        $publisher = new class implements EventPublisher {
            public function publish(OutboxRecord $event): void
            {
                throw new RuntimeException('broker NACK');
            }
        };

        try {
            (new OutboxRelay($store, $publisher))->runOnce();
            $this->fail('Expected publisher failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('broker NACK', $exception->getMessage());
        }
        $this->assertSame([], $store->published);
        $this->assertSame(['event-1'], $store->failed);
    }

    private function store(): FakeOutboxRelayStore
    {
        return new FakeOutboxRelayStore();
    }

    public function testBacklogIsSampledEveryFifteenSecondsIncludingAfterFailures(): void
    {
        $store = $this->store();
        $publisher = new class implements EventPublisher {
            public function publish(OutboxRecord $event): void {}
        };
        $now = 100.0;
        $relay = new OutboxRelay($store, $publisher, static function () use (&$now): float { return $now; });
        $relay->runOnce();
        $relay->runOnce();
        self::assertSame(1, $store->backlogReads);
        $now = 115.0;
        $store->failBacklog = true;
        $relay->runOnce();
        $relay->runOnce();
        self::assertSame(2, $store->backlogReads);
        self::assertCount(4, $store->published);
        $now = 130.0;
        $store->failBacklog = false;
        $relay->runOnce();
        self::assertSame(3, $store->backlogReads);
    }
}

final class FakeOutboxRelayStore implements OutboxRelayStore
{
    /** @var list<string> */
    public array $published = [];
    /** @var list<string> */
    public array $failed = [];
    public int $backlogReads = 0;
    public bool $failBacklog = false;

    public function backlogDepth(): int
    {
        $this->backlogReads++;
        if ($this->failBacklog) {
            throw new RuntimeException('metric unavailable');
        }
        return 3;
    }

    public function releaseStaleClaims(): int { return 0; }

    public function claimPending(int $limit = 50): array
    {
        return [new OutboxClaim(new OutboxRecord('event-1', 'user', 'user-1', 'user.registered.v1', 1, [], 'user-1', 'trace-1', id: 'event-1'), 'claim-token', '2026-10-20 00:00:00.000')];
    }

    public function markPublished(OutboxClaim $claim): bool { $this->published[] = $claim->record->eventId; return true; }

    public function markFailed(OutboxClaim $claim, int $retryDelaySeconds = 10): bool { $this->failed[] = $claim->record->eventId; return true; }
}
