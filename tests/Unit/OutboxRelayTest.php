<?php

declare(strict_types=1);

namespace Tests\Unit;

use Packages\Outbox\EventPublisher;
use Packages\Outbox\OutboxRecord;
use Packages\Outbox\OutboxRelay;
use Packages\Outbox\OutboxRelayStore;
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
}

final class FakeOutboxRelayStore implements OutboxRelayStore
{
    /** @var list<string> */
    public array $published = [];
    /** @var list<string> */
    public array $failed = [];

    public function releaseStaleClaims(int $olderThanSeconds = 300): int { return 0; }

    public function claimPending(int $limit = 50): array
    {
        return [new OutboxRecord('event-1', 'user', 'user-1', 'user.registered.v1', 1, [], 'user-1', 'trace-1', id: 'event-1')];
    }

    public function markPublished(string $id): void { $this->published[] = $id; }

    public function markFailed(string $id, int $retryDelaySeconds = 10): void { $this->failed[] = $id; }
}
