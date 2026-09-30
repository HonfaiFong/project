<?php

declare(strict_types=1);

namespace Services\AuthService\Domain\Entity;

final readonly class SocialBindSession
{
    public function __construct(
        public string $tokenHash,
        public string $socialIdentityId,
        public int $expiresAt,
        public ?string $phoneHash = null,
        public bool $phoneVerified = false,
        public ?string $verifiedPhone = null,
        public int $issuedAt = 0,
    ) {
    }
}
