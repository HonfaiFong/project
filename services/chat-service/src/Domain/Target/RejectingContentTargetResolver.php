<?php

declare(strict_types=1);

namespace Services\ChatService\Domain\Target;

final class RejectingContentTargetResolver implements ContentTargetResolver
{
    public function resolveAnonymousTarget(
        string $requesterUserId,
        string $scopeType,
        string $scopeId,
        string $anonymousRef,
    ): array {
        return ['allowed' => false, 'deleted' => false];
    }
}
