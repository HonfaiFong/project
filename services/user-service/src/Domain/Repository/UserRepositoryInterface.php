<?php

declare(strict_types=1);

namespace Services\UserService\Domain\Repository;

use Services\UserService\Domain\Entity\User;

interface UserRepositoryInterface
{
    public function findById(string $id): ?User;

    public function findByIdForUpdate(string $id): ?User;

    public function findByPhone(string $phone): ?User;

    /**
     * Finds the user bound to a caller-owned registration key (social logins
     * pass 'social:' + social_identities.id). Banned users stay visible so a
     * repeated registration cannot bypass the ban by creating a new account.
     */
    public function findBySocialRegistrationKey(string $registrationKey): ?User;

    /**
     * Reports whether a registration key is already taken, including by a
     * soft-deleted account. Such a key stays reserved by the unique index, so
     * registration can fail with an explicit error instead of an internal one.
     */
    public function socialRegistrationKeyExists(string $registrationKey): bool;

    public function findByEmail(string $email): ?User;

    /**
     * @param array<string> $ids
     * @return array<User>
     */
    public function batchFindByIds(array $ids): array;

    public function create(
        string $id,
        ?string $phone,
        ?string $email,
        string $registerChannel = 'default',
        int $riskLevel = 0,
        ?string $socialRegistrationKey = null
    ): User;

    public function update(string $id, ?string $phone, ?string $email): ?User;

    public function updateLastLogin(string $id): void;
}
