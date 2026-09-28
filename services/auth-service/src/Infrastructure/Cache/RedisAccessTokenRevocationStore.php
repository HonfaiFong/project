<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Cache;

use Redis;
use Services\AuthService\Domain\Repository\AccessTokenRevocationStoreInterface;

final class RedisAccessTokenRevocationStore implements AccessTokenRevocationStoreInterface
{
    private const TOKEN_KEY_PREFIX = 'auth:access-token-revoked:';
    private const DEVICE_KEY_PREFIX = 'auth:access-device-revoked:';

    public function __construct(private readonly Redis $redis)
    {
    }

    public function revokeToken(string $tokenHash, int $ttl): void
    {
        if ($ttl > 0) {
            $this->redis->setex(self::TOKEN_KEY_PREFIX . $tokenHash, $ttl, '1');
        }
    }

    public function revokeDevice(string $deviceId, int $ttl): void
    {
        if ($ttl > 0) {
            $this->redis->setex(self::DEVICE_KEY_PREFIX . $deviceId, $ttl, (string)time());
        }
    }

    public function isRevoked(string $tokenHash, string $deviceId, int $issuedAt): bool
    {
        if ($this->redis->exists(self::TOKEN_KEY_PREFIX . $tokenHash)) {
            return true;
        }

        if ($deviceId === '') {
            return false;
        }

        $revokedAt = $this->redis->get(self::DEVICE_KEY_PREFIX . $deviceId);

        return $revokedAt !== false && $issuedAt <= (int)$revokedAt;
    }
}
