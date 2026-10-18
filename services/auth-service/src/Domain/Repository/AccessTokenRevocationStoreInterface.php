<?php

declare(strict_types=1);

namespace Services\AuthService\Domain\Repository;

interface AccessTokenRevocationStoreInterface
{
    public function revokeToken(string $tokenHash, int $ttl): void;

    public function revokeDevice(string $deviceId, int $ttl): void;

    /** NumericDate in seconds, including fractional seconds for same-second sessions. */
    public function isRevoked(string $tokenHash, string $deviceId, float $issuedAt): bool;
}
