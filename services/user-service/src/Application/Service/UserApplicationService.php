<?php

declare(strict_types=1);

namespace Services\UserService\Application\Service;

use Contracts\Errors\ErrorCode;
use Contracts\Events\EventTypes;
use Packages\IdGenerator\Snowflake;
use Packages\Outbox\EventOutboxInterface;
use RuntimeException;
use Services\UserService\Domain\Entity\Persona;
use Services\UserService\Domain\Entity\User;
use Services\UserService\Domain\Exception\DuplicateUserException;
use Services\UserService\Domain\Repository\PersonaRepositoryInterface;
use Services\UserService\Domain\Repository\TransactionManagerInterface;
use Services\UserService\Domain\Repository\UserRepositoryInterface;

final class UserApplicationService
{
    private const MAX_PERSONAS_PER_USER = 5;

    public function __construct(
        private readonly UserRepositoryInterface $userRepo,
        private readonly PersonaRepositoryInterface $personaRepo,
        private readonly Snowflake $snowflake,
        private readonly TransactionManagerInterface $transactionManager,
        private readonly EventOutboxInterface $outbox,
    ) {
    }

    public function findOrCreateUserByPhone(string $phone): array
    {
        $user = $this->userRepo->findByPhone($phone);
        $isNew = false;

        if (!$user) {
            try {
                /** @var array{0: User, 1: bool} $registration */
                $registration = $this->transaction(function () use ($phone): array {
                    // Re-check inside the transaction so concurrent requests share the
                    // user created by the request that won the unique phone constraint.
                    $existing = $this->userRepo->findByPhone($phone);
                    if ($existing) {
                        return [$existing, false];
                    }

                    $id = $this->snowflake->nextId();
                    $user = $this->userRepo->create($id, $phone, null);

                    $personaId = $this->snowflake->nextId();
                    $persona = $this->personaRepo->create(
                        id: $personaId,
                        userId: $id,
                        type: 1,
                        nickname: 'User_' . substr($id, -4),
                        avatarKey: null,
                        bio: null
                    );

                    $this->recordEvent(
                        aggregateType: 'user',
                        aggregateId: $id,
                        eventType: EventTypes::USER_REGISTERED,
                        payload: $user->toArray() + ['persona_id' => $persona->id],
                    );
                    $this->recordEvent(
                        aggregateType: 'persona',
                        aggregateId: $persona->id,
                        eventType: EventTypes::PERSONA_UPDATED,
                        payload: $persona->toArray(),
                    );

                    return [$user, true];
                });
                [$user, $isNew] = $registration;
            } catch (DuplicateUserException $e) {
                // A unique phone constraint is the serialization point when two
                // registrations race before either transaction can read the row.
                $user = $this->userRepo->findByPhone($phone);
                if (!$user) {
                    throw $e;
                }
            }
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

    public function updateUser(
        string $userId,
        ?string $phone = null,
        ?string $email = null
    ): User {
        return $this->transaction(function () use ($userId, $phone, $email): User {
            $user = $this->userRepo->findById($userId);
            if (!$user) {
                throw new RuntimeException('User not found', ErrorCode::USER_NOT_FOUND->value);
            }

            $updated = $this->userRepo->update($userId, $phone, $email);
            if (!$updated) {
                throw new RuntimeException('Failed to update user', ErrorCode::INTERNAL_SERVER_ERROR->value);
            }

            $this->recordEvent(
                aggregateType: 'user',
                aggregateId: $updated->id,
                eventType: EventTypes::USER_UPDATED,
                payload: $updated->toArray(),
            );

            return $updated;
        });
    }

    public function createPersona(
        string $userId,
        int $type,
        string $nickname,
        ?string $avatarKey = null,
        ?string $bio = null
    ): Persona {
        return $this->transaction(function () use ($userId, $type, $nickname, $avatarKey, $bio): Persona {
            $user = $this->userRepo->findByIdForUpdate($userId);
            if (!$user) {
                throw new RuntimeException('User not found', ErrorCode::USER_NOT_FOUND->value);
            }

            $count = $this->personaRepo->countByUserId($userId);
            if ($count >= self::MAX_PERSONAS_PER_USER) {
                throw new RuntimeException('Persona limit reached', ErrorCode::PERSONA_LIMIT_EXCEEDED->value);
            }

            $id = $this->snowflake->nextId();
            $persona = $this->personaRepo->create($id, $userId, $type, $nickname, $avatarKey, $bio);
            $this->recordEvent(
                aggregateType: 'persona',
                aggregateId: $persona->id,
                eventType: EventTypes::PERSONA_UPDATED,
                payload: $persona->toArray(),
            );

            return $persona;
        });
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
        return $this->transaction(function () use ($personaId, $userId, $nickname, $avatarKey, $bio): Persona {
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

            $this->recordEvent(
                aggregateType: 'persona',
                aggregateId: $updated->id,
                eventType: EventTypes::PERSONA_UPDATED,
                payload: $updated->toArray(),
            );

            return $updated;
        });
    }

    /**
     * @return array<Persona>
     */
    public function listPersonas(string $userId): array
    {
        return $this->personaRepo->findByUserId($userId);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        return $this->transactionManager->transaction($callback);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recordEvent(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload
    ): void {
        $this->outbox->recordEvent(
            eventId: (string)$this->snowflake->nextId(),
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            eventType: $eventType,
            eventVersion: 1,
            payload: $payload,
        );
    }
}
