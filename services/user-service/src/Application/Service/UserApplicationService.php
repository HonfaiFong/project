<?php

declare(strict_types=1);

namespace Services\UserService\Application\Service;

use Contracts\Errors\ErrorCode;
use Packages\IdGenerator\Snowflake;
use RuntimeException;
use Services\UserService\Domain\Entity\Persona;
use Services\UserService\Domain\Entity\User;
use Services\UserService\Domain\Repository\PersonaRepositoryInterface;
use Services\UserService\Domain\Repository\UserRepositoryInterface;

final class UserApplicationService
{
    private const MAX_PERSONAS_PER_USER = 5;

    public function __construct(
        private readonly UserRepositoryInterface $userRepo,
        private readonly PersonaRepositoryInterface $personaRepo,
        private readonly Snowflake $snowflake
    ) {
    }

    public function findOrCreateUserByPhone(string $phone): array
    {
        $user = $this->userRepo->findByPhone($phone);
        $isNew = false;

        if (!$user) {
            $id = $this->snowflake->nextId();
            $user = $this->userRepo->create($id, $phone, null);
            $isNew = true;

            // Auto create default initial Persona
            $personaId = $this->snowflake->nextId();
            $this->personaRepo->create(
                id: $personaId,
                userId: $id,
                type: 1,
                nickname: 'User_' . substr($id, -4),
                avatarKey: null,
                bio: null
            );
        }

        $this->userRepo->updateLastLogin($user->id);

        return [
            'user'       => $user,
            'is_new_user'=> $isNew,
        ];
    }

    public function getUser(string $userId): User
    {
        $user = $this->userRepo->findById($userId);
        if (!$user) {
            throw new RuntimeException('User not found', ErrorCode::USER_NOT_FOUND->value);
        }
        return $user;
    }

    /**
     * @param array<string> $userIds
     * @return array<User>
     */
    public function batchGetUsers(array $userIds): array
    {
        return $this->userRepo->batchFindByIds($userIds);
    }

    public function createPersona(
        string $userId,
        int $type,
        string $nickname,
        ?string $avatarKey = null,
        ?string $bio = null
    ): Persona {
        $count = $this->personaRepo->countByUserId($userId);
        if ($count >= self::MAX_PERSONAS_PER_USER) {
            throw new RuntimeException('Persona limit reached', ErrorCode::PERSONA_LIMIT_EXCEEDED->value);
        }

        $id = $this->snowflake->nextId();
        return $this->personaRepo->create($id, $userId, $type, $nickname, $avatarKey, $bio);
    }

    public function getPersona(string $personaId): Persona
    {
        $persona = $this->personaRepo->findById($personaId);
        if (!$persona) {
            throw new RuntimeException('Persona not found', ErrorCode::PERSONA_NOT_FOUND->value);
        }
        return $persona;
    }

    /**
     * @param array<string> $personaIds
     * @return array<Persona>
     */
    public function batchGetPersonas(array $personaIds): array
    {
        return $this->personaRepo->batchFindByIds($personaIds);
    }

    public function updatePersona(
        string $personaId,
        string $userId,
        ?string $nickname = null,
        ?string $avatarKey = null,
        ?string $bio = null
    ): Persona {
        $persona = $this->personaRepo->findById($personaId);
        if (!$persona) {
            throw new RuntimeException('Persona not found', ErrorCode::PERSONA_NOT_FOUND->value);
        }
        if ($persona->userId !== $userId) {
            throw new RuntimeException('Forbidden persona modification', ErrorCode::PERSONA_FORBIDDEN->value);
        }

        $updated = $this->personaRepo->update($personaId, $userId, $nickname, $avatarKey, $bio);
        if (!$updated) {
            throw new RuntimeException('Failed to update persona', ErrorCode::INTERNAL_SERVER_ERROR->value);
        }
        return $updated;
    }

    /**
     * @return array<Persona>
     */
    public function listPersonas(string $userId): array
    {
        return $this->personaRepo->findByUserId($userId);
    }
}
