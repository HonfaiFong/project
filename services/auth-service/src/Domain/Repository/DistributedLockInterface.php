<?php

declare(strict_types=1);

namespace Services\AuthService\Domain\Repository;

interface DistributedLockInterface
{
    public function acquire(string $key, int $ttlSeconds): ?string;

    public function release(string $key, string $owner): void;
}
