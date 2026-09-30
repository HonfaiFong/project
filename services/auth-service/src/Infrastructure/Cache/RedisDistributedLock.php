<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Cache;

use Redis;
use Services\AuthService\Domain\Repository\DistributedLockInterface;

final class RedisDistributedLock implements DistributedLockInterface
{
    public function __construct(private readonly Redis $redis)
    {
    }

    public function acquire(string $key, int $ttlSeconds): ?string
    {
        $owner = bin2hex(random_bytes(16));
        $acquired = $this->redis->set($key, $owner, ['nx', 'ex' => $ttlSeconds]);

        return $acquired ? $owner : null;
    }

    public function release(string $key, string $owner): void
    {
        $this->redis->eval(
            'if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end',
            [$key, $owner],
            1
        );
    }
}
