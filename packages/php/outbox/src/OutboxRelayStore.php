<?php

declare(strict_types=1);

namespace Packages\Outbox;

interface OutboxRelayStore
{
    public function releaseStaleClaims(): int;

    /** @return list<OutboxClaim> */
    public function claimPending(int $limit = 50): array;

    public function markPublished(OutboxClaim $claim): bool;

    public function markFailed(OutboxClaim $claim, int $retryDelaySeconds = 10): bool;
}
