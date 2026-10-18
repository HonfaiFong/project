<?php

declare(strict_types=1);

namespace Services\AuthService\Domain\Repository;

use Services\AuthService\Domain\Entity\Device;
use Services\AuthService\Domain\Entity\RefreshToken;

interface AuthRepositoryInterface
{
    public function findOrCreateDevice(
        string $userId,
        string $deviceUuid,
        string $platform,
        string $appVersion,
        ?string $pushToken,
        ?string $ip
    ): Device;

    public function deviceBelongsToUser(string $userId, string $deviceId): bool;

    /**
     * Require an existing device owned by the user and serialize creation with device logout.
     * Return createdAt as the UTC microsecond issuance instant captured while holding that lock;
     * preserve its precision in the returned entity even if database storage truncates it.
     */
    public function createRefreshToken(
        string $userId,
        string $deviceId,
        string $tokenHash,
        string $expiresAt
    ): RefreshToken;

    /**
     * 原子消费有效刷新令牌并创建替代令牌。
     */
    public function rotateRefreshToken(
        string $tokenHash,
        string $newTokenHash,
        string $newExpiresAt
    ): ?RefreshToken;

    public function findRefreshToken(string $tokenHash): ?RefreshToken;

    /**
     * An active credential logs out every active refresh token on its device atomically.
     * Serialize with rotation on the device row. Rotated/expired credentials return false;
     * logged-out credentials retain their original loggedOutAt for recovery.
     */
    public function revokeRefreshToken(string $tokenHash): bool;
}
