<?php

declare(strict_types=1);

namespace Services\SocialService\Domain\Service;

interface ResourceAccessInterface
{
    /**
     * ContentService owns posts/comments. Social asks it to validate that the
     * authenticated account may interact with the resource rather than
     * opening content_db itself.
     */
    /**
     * @return array{resource_author_user_id:string,root_author_user_id:string}
     */
    public function assertAccessible(string $actorUserId, int $targetType, string $targetId): array;
}
