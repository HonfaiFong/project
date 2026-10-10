<?php

declare(strict_types=1);

namespace Services\UserService\Domain\Repository;

use Services\UserService\Domain\Entity\Persona;

interface PersonaRepositoryInterface
{
    public function findById(string $id): ?Persona;

    /**
     * @param array<string> $ids
     * @return array<Persona>
     */
    public function batchFindByIds(array $ids): array;

    /**
     * @return array<Persona>
     */
    public function findByUserId(string $userId): array;

    /** Return the one server-owned anonymous identity, if it exists. */
    public function findInternalAliasByUserIdForUpdate(string $userId): ?Persona;

    public function countByUserId(string $userId): int;

    public function create(
        string $id,
        string $userId,
        int $type,
        string $nickname,
        ?string $avatarKey,
        ?string $bio,
        bool $isInternalAlias = false,
    ): Persona;

    public function update(
        string $id,
        string $userId,
        ?string $nickname,
        ?string $avatarKey,
        ?string $bio
    ): ?Persona;
}
