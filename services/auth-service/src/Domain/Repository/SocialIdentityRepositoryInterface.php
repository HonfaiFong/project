<?php

declare(strict_types=1);

namespace Services\AuthService\Domain\Repository;

use Services\AuthService\Domain\Auth\ExternalIdentity;
use Services\AuthService\Domain\Entity\SocialIdentity;

interface SocialIdentityRepositoryInterface
{
    public function findByExternalIdentity(ExternalIdentity $identity): ?SocialIdentity;

    public function reserve(ExternalIdentity $identity): SocialIdentity;

    public function findById(string $id): ?SocialIdentity;

    public function beginRegistration(string $identityId): SocialIdentity;

    public function completeRegistration(string $identityId, string $userId): SocialIdentity;

    public function bindToUser(string $identityId, string $userId): SocialIdentity;

    public function touchLastLogin(string $identityId): void;
}
