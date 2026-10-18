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

    /** Atomically persist the first revocation time; retain the row for idempotent recovery. */
    public function revokeRefreshToken(string $tokenHash): bool;
}
