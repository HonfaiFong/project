<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Cache;

use Contracts\Errors\ErrorCode;
use Redis;
use RuntimeException;
use Services\AuthService\Domain\Repository\AccessTokenRevocationStoreInterface;
use Throwable;

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
            $this->write(self::TOKEN_KEY_PREFIX . $tokenHash, $ttl, '1');
        }
    }

    public function revokeDevice(string $deviceId, int $ttl): void
    {
        if ($ttl > 0) {
            $this->write(self::DEVICE_KEY_PREFIX . $deviceId, $ttl, sprintf('%.6F', microtime(true)));
        }
    }

    public function isRevoked(string $tokenHash, string $deviceId, float $issuedAt): bool
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
            || ($deviceId !== '' && $deviceRevokedAt !== false && $issuedAt <= (float)$deviceRevokedAt);
    }

    /**
     * 撤销标记是安全状态，写入失败必须向上传播，否则注销会错误地报告成功。
     * phpredis 在部分传输失败时会返回 false 而不抛异常，因此两种失败都要覆盖。
     */
    private function write(string $key, int $ttl, string $value): void
    {
        try {
            $written = $this->redis->setex($key, $ttl, $value);
        } catch (Throwable $e) {
            throw new RuntimeException(
                ErrorCode::SERVICE_UNAVAILABLE->message(),
                ErrorCode::SERVICE_UNAVAILABLE->value,
                $e,
            );
        }

        if ($written !== true) {
            throw new RuntimeException(
                ErrorCode::SERVICE_UNAVAILABLE->message(),
                ErrorCode::SERVICE_UNAVAILABLE->value,
            );
        }
    }
}
