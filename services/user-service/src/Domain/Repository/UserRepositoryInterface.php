<?php

declare(strict_types=1);

namespace Services\UserService\Domain\Repository;

use Services\UserService\Domain\Entity\User;

interface UserRepositoryInterface
{
    public function findById(string $id): ?User;

    public function findByPhone(string $phone): ?User;

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
        int $riskLevel = 0
    ): User;

    public function update(string $id, ?string $phone, ?string $email): ?User;

    public function updateLastLogin(string $id): void;
}
