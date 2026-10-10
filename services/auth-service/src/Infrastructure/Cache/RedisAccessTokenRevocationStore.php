<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Cache;

use Contracts\Errors\ErrorCode;
use Redis;
use RuntimeException;
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
        // 缺失键在结果数组中表示为 false；命令执行失败则不会返回数组。
        $values = $this->redis->mGet([
            self::TOKEN_KEY_PREFIX . $tokenHash,
            self::DEVICE_KEY_PREFIX . $deviceId,
        ]);
        if (!is_array($values) || count($values) !== 2) {
            throw new RuntimeException(
                ErrorCode::SERVICE_UNAVAILABLE->message(),
                ErrorCode::SERVICE_UNAVAILABLE->value,
            );
        }

        [$tokenRevoked, $deviceRevokedAt] = array_values($values);

        return $tokenRevoked !== false
            || ($deviceId !== '' && $deviceRevokedAt !== false && $issuedAt <= (int)$deviceRevokedAt);
    }
}
