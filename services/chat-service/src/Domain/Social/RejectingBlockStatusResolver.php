<?php

declare(strict_types=1);

namespace Services\ChatService\Domain\Social;

/** Fails closed until the real SocialService block RPC is configured. */
final class RejectingBlockStatusResolver implements BlockStatusResolver
{
    public function isBlockedEitherWay(string $firstUserId, string $secondUserId): bool
    {
        return true;
    }
}
