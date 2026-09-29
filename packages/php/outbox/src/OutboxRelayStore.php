<?php

declare(strict_types=1);

namespace Packages\Outbox;

interface OutboxRelayStore
{
    public function releaseStaleClaims(int $olderThanSeconds = 300): int;

    /** @return array<OutboxRecord> */
    public function claimPending(int $limit = 50): array;

    public function markPublished(string $id): void;

    public function markFailed(string $id, int $retryDelaySeconds = 10): void;
}
