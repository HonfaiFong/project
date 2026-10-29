<?php
declare(strict_types=1);
namespace Services\ContentService\Domain\Social;
interface BatchBlockStatusResolver extends BlockStatusResolver
{
    /** @param list<string> $targets @return list<string> */
    public function blockedUserIds(string $actor, array $targets): array;
}
