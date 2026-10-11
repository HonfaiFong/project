<?php

declare(strict_types=1);

namespace Services\ChatService\Domain\Social;

final class AllowAllBlockStatusResolver implements BlockStatusResolver
{
    public function isBlockedEitherWay(string $firstUserId, string $secondUserId): bool
    {
        return false;
    }
}
