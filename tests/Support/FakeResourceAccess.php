<?php

declare(strict_types=1);

namespace Tests\Support;

use Services\SocialService\Domain\Service\ResourceAccessInterface;

/** Social 并发/集成测试使用的资源授权替身：固定同一作者账号，不触发拉黑分支。 */
final class FakeResourceAccess implements ResourceAccessInterface
{
    /** @return array{resource_author_user_id:string,root_author_user_id:string} */
    public function assertAccessible(string $actorUserId, int $targetType, string $targetId): array
    {
        return [
            'resource_author_user_id' => '99',
            'root_author_user_id' => '99',
        ];
    }
}
