<?php

declare(strict_types=1);

namespace Services\AuthService\Domain\Entity;

final readonly class RefreshToken
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $deviceId,
        public string $tokenHash,
        public string $expiresAt,
        public ?string $revokedAt = null,
        public ?string $createdAt = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->revokedAt === null && strtotime($this->expiresAt) > time();
    }
}
