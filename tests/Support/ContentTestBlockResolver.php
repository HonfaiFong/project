<?php

declare(strict_types=1);

namespace Tests\Support;

use Services\ContentService\Domain\Social\BlockStatusResolver;

/** Content 集成测试使用的拉黑状态替身：键为 "userA|userB"，双向命中。 */
final class ContentTestBlockResolver implements BlockStatusResolver
{
    /** @var array<string,bool> */
    public array $blocked = [];

    public function isBlockedEitherWay(string $firstUserId, string $secondUserId): bool
    {
        return ($this->blocked[$firstUserId . '|' . $secondUserId] ?? false)
            || ($this->blocked[$secondUserId . '|' . $firstUserId] ?? false);
    }
}
