<?php

declare(strict_types=1);

namespace Packages\Outbox;

final readonly class OutboxClaim
{
    public function __construct(
        public OutboxRecord $record,
        public string $claimToken,
        public string $leaseUntil,
    ) {
    }
}
