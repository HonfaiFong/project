<?php

declare(strict_types=1);

namespace Services\UserService\Application\Service;

use Contracts\Errors\ErrorCode;
use Contracts\Events\EventTypes;
use Packages\IdGenerator\Snowflake;
use Packages\Phone\PhoneNormalizer;
use Packages\Outbox\EventOutboxInterface;
use Packages\Tracing\TraceContext;
use RuntimeException;
use Random\RandomException;
use Throwable;
use Services\UserService\Domain\Entity\Persona;
use Services\UserService\Domain\Entity\PersonaType;
use Services\UserService\Domain\Entity\User;
use Services\UserService\Domain\Exception\DuplicateUserException;
use Services\UserService\Domain\Repository\PersonaRepositoryInterface;
use Services\UserService\Domain\Repository\TransactionManagerInterface;
use Services\UserService\Domain\Repository\UserRepositoryInterface;

final class UserApplicationService
{
    private const MAX_PERSONAS_PER_USER = 5;
    private const MAX_PERSONA_NICKNAME_LENGTH = 64;
    private const MAX_PERSONA_AVATAR_KEY_LENGTH = 255;
    private const MAX_PERSONA_BIO_LENGTH = 500;

    /** Kept in sync with the users.social_registration_key column width. */
    private const MAX_REGISTRATION_KEY_LENGTH = 128;

    public function __construct(
        private readonly UserRepositoryInterface $userRepo,
        private readonly PersonaRepositoryInterface $personaRepo,
        private readonly Snowflake $snowflake,
        private readonly TransactionManagerInterface $transactionManager,
        private readonly EventOutboxInterface $outbox,
        private readonly PhoneNormalizer $phoneNormalizer,
    ) {
    }

    /** @throws Throwable */
    public function findOrCreateUserByPhone(string $phone, string $registerChannel = 'default'): array
    {
        $phone = $this->phoneNormalizer->normalize($phone);
        $registerChannel = $this->normalizeRegisterChannel($registerChannel);
        $user = $this->userRepo->findByPhone($phone);
        $isNew = false;

        if (!$user) {
            try {
                /** @var array{0: User, 1: bool} $registration */
                $registration = $this->transaction(function () use ($phone, $registerChannel): array {
                    // Re-check inside the transaction so concurrent requests share the
                    // user created by the request that won the unique phone constraint.
                    $existing = $this->userRepo->findByPhone($phone);
                    if ($existing) {
                        return [$existing, false];
                    }

                    return [$this->registerUser($this->snowflake->nextId(), $phone, $registerChannel), true];
                });
                [$user, $isNew] = $registration;
            } catch (DuplicateUserException $e) {
                // A unique phone constraint is the serialization point when two
                // registrations race before either transaction can read the row.
                $user = $this->userRepo->findByPhone($phone);
                if ($user === null) {
                    throw new RuntimeException(
                        ErrorCode::INTERNAL_SERVER_ERROR->message(),
                        ErrorCode::INTERNAL_SERVER_ERROR->value,
                        $e
                    );
                }
            }
        }

        $this->assertUserCanAuthenticate($user);
        $this->userRepo->updateLastLogin($user->id);

        return [
            'user'       => $user,
            'is_new_user'=> $isNew,
        ];
    }

    /**
     * Registers a social login identity supplied by AuthService.
     *
     * The registration key stays opaque to this service: it is a caller-owned
     * idempotency key ('social:' + social_identities.id), never the OAuth
     * subject id, and it is only stored on the created user row.
     *
     * @return array{user: User, is_new_user: bool}
     * @throws Throwable
     */
    public function findOrCreateUserBySocialIdentity(
        string $registrationKey,
        string $registerChannel = 'default'
    ): array {
        $registrationKey = $this->normalizeRegistrationKey($registrationKey);
        $registerChannel = $this->normalizeRegisterChannel($registerChannel);
        $user = $this->userRepo->findBySocialRegistrationKey($registrationKey);
        $isNew = false;

        if (!$user) {
            try {
                /** @var array{0: User, 1: bool} $registration */
                $registration = $this->transaction(function () use ($registrationKey, $registerChannel): array {
                    // Re-check inside the transaction so a repeat or retried registration
                    // shares the user created by the request that won the unique key.
                    $existing = $this->userRepo->findBySocialRegistrationKey($registrationKey);
                    if ($existing) {
                        return [$existing, false];
                    }

                    return [
                        $this->registerUser(
                            $this->snowflake->nextId(),
                            null,
                            $registerChannel,
                            $registrationKey,
                        ),
                        true,
                    ];
                });
                [$user, $isNew] = $registration;
            } catch (DuplicateUserException $e) {
                // The unique registration key is the serialization point when two
                // registrations race before either transaction can read the row.
                $user = $this->userRepo->findBySocialRegistrationKey($registrationKey);
                if ($user === null) {
                    if ($this->userRepo->socialRegistrationKeyExists($registrationKey)) {
                        // A soft-deleted account still owns the key: registration fails
                        // closed instead of resurrecting a removed user.
                        throw new RuntimeException(
                            ErrorCode::USER_NOT_FOUND->message(),
                            ErrorCode::USER_NOT_FOUND->value,
                            $e
                        );
                    }
                    throw new RuntimeException(
                        ErrorCode::INTERNAL_SERVER_ERROR->message(),
                        ErrorCode::INTERNAL_SERVER_ERROR->value,
                        $e
                    );
                }
            }
        }

        $this->assertUserCanAuthenticate($user);
        $this->userRepo->updateLastLogin($user->id);

        return [
            'user'        => $user,
            'is_new_user' => $isNew,
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

    /** @throws Throwable */
    public function updateUser(
        string $userId,
        ?string $phone = null,
        ?string $email = null
    ): User {
        $phone = $phone !== null ? $this->phoneNormalizer->normalize($phone) : null;

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
                eventKey: $updated->id,
                eventType: EventTypes::USER_UPDATED,
                payload: $updated->toArray(),
            );

            return $updated;
        });
    }

    /**
     * Compatibility entry point for the legacy RPC.
     *
     * The old `type` field is retained in the protobuf so older clients can
     * be upgraded independently. Zero (the proto3 default) and ALIAS both
     * mean "create an alias"; MAIN and unknown values are rejected.
     *
     * @throws Throwable
     */
    public function createPersona(
        string $userId,
        int $type,
        string $nickname,
        ?string $avatarKey = null,
        ?string $bio = null
    ): Persona {
        if ($type === PersonaType::MAIN->value) {
            throw new RuntimeException(
                'The main persona is created during registration and cannot be created here',
                ErrorCode::PERSONA_FORBIDDEN->value,
            );
        }
        if ($type !== 0 && $type !== PersonaType::ALIAS->value) {
            throw new RuntimeException('Unsupported persona type', ErrorCode::INVALID_ARGUMENT->value);
        }

        return $this->createAliasPersona($userId, $nickname, $avatarKey, $bio);
    }

    /** @throws Throwable */
    public function createAliasPersona(
        string $userId,
        string $nickname,
        ?string $avatarKey = null,
        ?string $bio = null
    ): Persona {
        $nickname = $this->normalizePersonaNickname($nickname);
        $avatarKey = $this->normalizePersonaNullableText(
            $avatarKey,
            self::MAX_PERSONA_AVATAR_KEY_LENGTH,
            'avatar_key',
        );
        $bio = $this->normalizePersonaNullableText($bio, self::MAX_PERSONA_BIO_LENGTH, 'bio');

        return $this->transaction(function () use ($userId, $nickname, $avatarKey, $bio): Persona {
            $user = $this->userRepo->findByIdForUpdate($userId);
            if (!$user) {
                throw new RuntimeException('User not found', ErrorCode::USER_NOT_FOUND->value);
            }
            if (!$user->isActive()) {
                throw new RuntimeException(ErrorCode::USER_BANNED->message(), ErrorCode::USER_BANNED->value);
            }

            $count = $this->personaRepo->countByUserId($userId);
            if ($count >= self::MAX_PERSONAS_PER_USER) {
                throw new RuntimeException('Persona limit reached', ErrorCode::PERSONA_LIMIT_EXCEEDED->value);
            }

            $id = $this->snowflake->nextId();
            $persona = $this->personaRepo->create(
                $id,
                $userId,
                PersonaType::ALIAS->value,
                $nickname,
                $avatarKey,
                $bio,
            );
            $this->recordEvent(
                aggregateType: 'persona',
                aggregateId: $persona->id,
                eventKey: $userId,
                eventType: EventTypes::PERSONA_UPDATED,
                payload: $persona->toArray(),
            );

            return $persona;
        });
    }

    /**
     * Validate the account and persona together before an identity-bearing
     * operation. Callers must use the returned entity and never trust a
     * client-supplied user_id or persona status after this point.
     *
     * @throws RuntimeException
     */
    public function assertPersonaUsableByUser(string $userId, string $personaId): Persona
    {
        $persona = $this->personaRepo->findById($personaId);
        if (!$persona || $persona->userId !== $userId || $persona->status !== 1) {
            throw new RuntimeException(ErrorCode::PERSONA_FORBIDDEN->message(), ErrorCode::PERSONA_FORBIDDEN->value);
        }

        $user = $this->userRepo->findById($userId);
        if (!$user || !$user->isActive()) {
            throw new RuntimeException(ErrorCode::USER_BANNED->message(), ErrorCode::USER_BANNED->value);
        }

        return $persona;
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

    /** @throws Throwable */
    public function updatePersona(
        string $personaId,
        string $userId,
        ?string $nickname = null,
        ?string $avatarKey = null,
        ?string $bio = null
    ): Persona {
        return $this->transaction(function () use ($personaId, $userId, $nickname, $avatarKey, $bio): Persona {
            $this->assertPersonaUsableByUser($userId, $personaId);
            $nickname = $nickname !== null ? $this->normalizePersonaNickname($nickname) : null;
            $avatarKey = $this->normalizePersonaNullableText(
                $avatarKey,
                self::MAX_PERSONA_AVATAR_KEY_LENGTH,
                'avatar_key',
            );
            $bio = $this->normalizePersonaNullableText($bio, self::MAX_PERSONA_BIO_LENGTH, 'bio');

            $updated = $this->personaRepo->update($personaId, $userId, $nickname, $avatarKey, $bio);
            if (!$updated) {
                throw new RuntimeException('Failed to update persona', ErrorCode::INTERNAL_SERVER_ERROR->value);
            }

            $this->recordEvent(
                aggregateType: 'persona',
                aggregateId: $updated->id,
                eventKey: $userId,
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
        $user = $this->userRepo->findById($userId);
        if (!$user) {
            throw new RuntimeException('User not found', ErrorCode::USER_NOT_FOUND->value);
        }
        if (!$user->isActive()) {
            throw new RuntimeException(ErrorCode::USER_BANNED->message(), ErrorCode::USER_BANNED->value);
        }

        return $this->personaRepo->findByUserId($userId);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws Throwable
     */
    private function transaction(callable $callback): mixed
    {
        return $this->transactionManager->transaction($callback);
    }

    /**
     * Creates the user row together with its default persona and the
     * registration events inside the caller's transaction, so every new
     * account (phone or social registration) gets the same defaults.
     * @throws RandomException
     */
    private function registerUser(
        string $id,
        ?string $phone,
        string $registerChannel,
        ?string $socialRegistrationKey = null
    ): User {
        $user = $this->userRepo->create(
            id: $id,
            phone: $phone,
            email: null,
            registerChannel: $registerChannel,
            socialRegistrationKey: $socialRegistrationKey,
        );

        $personaId = $this->snowflake->nextId();
        $persona = $this->personaRepo->create(
            id: $personaId,
            userId: $id,
            type: PersonaType::MAIN->value,
            nickname: 'User_' . substr($id, -4),
            avatarKey: null,
            bio: null
        );

        $this->recordEvent(
            aggregateType: 'user',
            aggregateId: $id,
            eventKey: $id,
            eventType: EventTypes::USER_REGISTERED,
            payload: $user->toArray() + ['persona_id' => $persona->id],
        );
        $this->recordEvent(
            aggregateType: 'persona',
            aggregateId: $persona->id,
            eventKey: $id,
            eventType: EventTypes::PERSONA_UPDATED,
            payload: $persona->toArray(),
        );

        return $user;
    }

    private function normalizeRegisterChannel(string $registerChannel): string
    {
        $registerChannel = trim($registerChannel);

        return $registerChannel !== '' ? $registerChannel : 'default';
    }

    private function normalizeRegistrationKey(string $registrationKey): string
    {
        $registrationKey = trim($registrationKey);
        if ($registrationKey === '') {
            throw new RuntimeException('registration_key is required', ErrorCode::INVALID_ARGUMENT->value);
        }
        if (strlen($registrationKey) > self::MAX_REGISTRATION_KEY_LENGTH) {
            throw new RuntimeException('registration_key is too long', ErrorCode::INVALID_ARGUMENT->value);
        }

        return $registrationKey;
    }

    private function normalizePersonaNickname(string $nickname): string
    {
        $nickname = trim($nickname);
        if ($nickname === '') {
            throw new RuntimeException('nickname is required', ErrorCode::INVALID_ARGUMENT->value);
        }
        if ($this->personaTextLength($nickname) > self::MAX_PERSONA_NICKNAME_LENGTH) {
            throw new RuntimeException('nickname is too long', ErrorCode::INVALID_ARGUMENT->value);
        }

        return $nickname;
    }

    private function normalizePersonaNullableText(?string $value, int $maxLength, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($this->personaTextLength($value) > $maxLength) {
            throw new RuntimeException($field . ' is too long', ErrorCode::INVALID_ARGUMENT->value);
        }

        return $value;
    }

    private function personaTextLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /**
     * @param array<string, mixed> $payload
     * @throws RandomException
     */
    private function recordEvent(
        string $aggregateType,
        string $aggregateId,
        string $eventKey,
        string $eventType,
        array $payload
    ): void {
        $this->outbox->recordEvent(
            eventId: $this->snowflake->nextId(),
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            eventType: $eventType,
            eventVersion: 1,
            payload: $payload,
            eventKey: $eventKey,
            traceId: TraceContext::current()->traceId,
        );
    }

    private function assertUserCanAuthenticate(User $user): void
    {
        if (!$user->isActive()) {
            throw new RuntimeException(
                ErrorCode::USER_BANNED->message(),
                ErrorCode::USER_BANNED->value
            );
        }
    }
}
