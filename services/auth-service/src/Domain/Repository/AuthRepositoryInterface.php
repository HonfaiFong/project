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
     * Atomically consume an active refresh token and create its replacement.
     */
    public function rotateRefreshToken(
        string $tokenHash,
        string $newTokenHash,
        string $newExpiresAt
    ): ?RefreshToken;

    public function findRefreshToken(string $tokenHash): ?RefreshToken;

    public function revokeRefreshToken(string $tokenHash): bool;
}
