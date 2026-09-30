<?php

declare(strict_types=1);

namespace Services\AuthService\Domain\Repository;

use Services\AuthService\Domain\Entity\SocialBindSession;

interface SocialBindSessionStoreInterface
{
    /**
     * @return array{bind_token: string, bind_token_expires_at: int}
     */
    public function issue(string $socialIdentityId, int $ttlSeconds): array;

    public function verify(string $bindToken): ?SocialBindSession;

    public function setPhone(string $bindToken, string $phone): SocialBindSession;

    public function assertPhone(string $bindToken, string $phone): SocialBindSession;

    public function markPhoneVerified(string $bindToken, string $phone): SocialBindSession;

    public function consume(string $bindToken): void;
}
