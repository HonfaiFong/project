<?php

declare(strict_types=1);

namespace Services\ContentService\Domain\Social;

interface BlockStatusResolver
{
    /** True when either account blocks the other. */
    public function isBlockedEitherWay(string $firstUserId, string $secondUserId): bool;
}
