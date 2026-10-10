<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Cache;

use Random\RandomException;
use Redis;
use Services\AuthService\Domain\Repository\DistributedLockInterface;

final readonly class RedisDistributedLock implements DistributedLockInterface
{
    public function __construct(private Redis $redis)
    {
    }

    /** @throws RandomException when the CSPRNG cannot produce the lock owner */
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
