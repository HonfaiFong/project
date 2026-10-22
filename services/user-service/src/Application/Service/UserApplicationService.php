<?php

declare(strict_types=1);

namespace Services\UserService\Application\Service;

use Contracts\Errors\ErrorCode;
use Contracts\Events\EventTypes;
use Packages\IdGenerator\Snowflake;
use Packages\Phone\PhoneNormalizer;
use Packages\Outbox\EventOutboxInterface;
use Packages\Tracing\TraceContext;
use Packages\Identity\PersonaMode;
use Packages\Identity\PersonaOperation;
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
    private const MAX_PERSONA_NICKNAME_LENGTH = 64;
    private const MAX_PERSONA_AVATAR_KEY_LENGTH = 255;
    private const MAX_PERSONA_BIO_LENGTH = 500;

    /** 与 users.social_registration_key 列宽保持一致。 */
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

        if (empty($user)) {
            try {
                /** @var array{0: User, 1: bool} $registration */
                $registration = $this->transaction(function () use ($phone, $registerChannel): array {
                    // 在事务内重新检查，让并发请求复用成功通过手机号唯一约束的请求所创建的用户。
                    $existing = $this->userRepo->findByPhone($phone);
                    if (!empty($existing)) {
                        return [$existing, false];
                    }

                    return [$this->registerUser($this->snowflake->nextId(), $phone, $registerChannel), true];
                });
                [$user, $isNew] = $registration;
            } catch (DuplicateUserException $e) {
                // 两个注册请求在任一事务读到行之前发生竞争时，手机号唯一约束充当串行化点。
                $user = $this->userRepo->findByPhone($phone);
                if (empty($user)) {
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
     * 注册由 AuthService 提供的社交登录身份。
     *
     * 此服务不解释注册键，它是调用方持有的幂等键（'social:' + social_identities.id），不是 OAuth 主体 ID，仅保存在创
     * 建的用户行中。
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

        if (empty($user)) {
            try {
                /** @var array{0: User, 1: bool} $registration */
                $registration = $this->transaction(function () use ($registrationKey, $registerChannel): array {
                    // 在事务内重新检查，让重复或重试注册复用成功通过唯一约束的请求所创建的用户。
                    $existing = $this->userRepo->findBySocialRegistrationKey($registrationKey);
                    if (!empty($existing)) {
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
                // 两个注册请求在任一事务读到行之前发生竞争时，注册键唯一约束充当串行化点。
                $user = $this->userRepo->findBySocialRegistrationKey($registrationKey);
                if (empty($user)) {
                    if ($this->userRepo->socialRegistrationKeyExists($registrationKey)) {
                        // 软删除账户仍占用注册键；须拒绝注册，不能恢复已删除用户。
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
        if (empty($user)) {
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
            if (empty($user)) {
                throw new RuntimeException('User not found', ErrorCode::USER_NOT_FOUND->value);
            }

            $updated = $this->userRepo->update($userId, $phone, $email);
            if (empty($updated)) {
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
     * The old method remains in the wire contract so clients can be upgraded
     * independently, but it is intentionally disabled. Alias identities are
     * server-owned and are resolved only by resolvePersonaMode(). Keeping the
     * rejection before any normalization or transaction also guarantees that
     * legacy calls cannot create database side effects.
     *
     * @throws RuntimeException
     */
    public function createPersona(
        string $userId,
        int $type,
        string $nickname,
        ?string $avatarKey = null,
        ?string $bio = null
    ): Persona {
        throw new RuntimeException(
            'Creating personas is disabled; choose MAIN or a server-owned anonymous mode',
            ErrorCode::PERSONA_FORBIDDEN->value,
        );
    }

    /**
     * Legacy alias creation is deliberately disabled. The method is retained
     * only for source and wire compatibility; it must never normalize input,
     * lock a user row, or open a transaction before rejecting the call.
     *
     * @throws RuntimeException
     */
    public function createAliasPersona(
        string $userId,
        string $nickname,
        ?string $avatarKey = null,
        ?string $bio = null
    ): Persona {
        throw new RuntimeException(
            'Creating personas is disabled; anonymous identity is server-owned',
            ErrorCode::PERSONA_FORBIDDEN->value,
        );
    }

    /**
     * Return or create the one server-owned anonymous identity for an account.
     *
     * This is deliberately separate from the legacy create RPC. Historical
     * alias rows remain untouched and are never silently promoted.
     *
     * @throws Throwable
     */
    public function ensureInternalAlias(string $userId): Persona
    {
        return $this->transaction(function () use ($userId): Persona {
            $user = $this->userRepo->findByIdForUpdate($userId);
            if (empty($user)) {
                throw new RuntimeException('User not found', ErrorCode::USER_NOT_FOUND->value);
            }
            if (!$user->isActive()) {
                throw new RuntimeException(ErrorCode::USER_BANNED->message(), ErrorCode::USER_BANNED->value);
            }

            // This lookup includes soft-deleted rows. The unique generated
            // key intentionally reserves the identity name forever, so a
            // deleted canonical alias must be repaired explicitly rather
            // than turning a duplicate-key error into a generic 500.
            $existing = $this->personaRepo->findInternalAliasByUserIdForUpdate($userId);
            if (!empty($existing)) {
                if ($existing->deletedAt !== null || $existing->status !== 1) {
                    throw new RuntimeException(
                        'The account has no usable anonymous identity; manual recovery is required',
                        ErrorCode::PERSONA_FORBIDDEN->value,
                    );
                }

                return $existing;
            }

            $persona = $this->personaRepo->create(
                id: $this->snowflake->nextId(),
                userId: $userId,
                type: PersonaType::ALIAS->value,
                nickname: '匿名用户',
                avatarKey: 'system:anonymous',
                bio: null,
                isInternalAlias: true,
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
     * Resolve a requested mode to a current, account-owned persona.
     *
     * The caller supplies the authenticated account id; any user id in a
     * business payload must be ignored by callers before invoking this method.
     *
     * @throws Throwable
     */
    public function resolvePersonaMode(string $userId, int $mode, int $operation): Persona
    {
        $requestedMode = PersonaMode::tryFrom($mode);
        if ($requestedMode === null || $requestedMode === PersonaMode::UNSPECIFIED) {
            throw new RuntimeException('identity mode is required', ErrorCode::PERSONA_FORBIDDEN->value);
        }
        $requestedOperation = PersonaOperation::tryFrom($operation);
        if ($requestedOperation === null || $requestedOperation === PersonaOperation::UNSPECIFIED) {
            throw new RuntimeException('persona operation is required', ErrorCode::PERSONA_FORBIDDEN->value);
        }
        if ($requestedMode === PersonaMode::ALIAS && !$requestedOperation->allowsAlias()) {
            throw new RuntimeException(ErrorCode::PERSONA_FORBIDDEN->message(), ErrorCode::PERSONA_FORBIDDEN->value);
        }
        $persona = match ($requestedMode) {
            PersonaMode::MAIN => $this->findMainPersona($userId),
            PersonaMode::ALIAS => $this->ensureInternalAlias($userId),
        };

        return $this->assertPersonaUsableByUser($userId, $persona->id, $operation);
    }

    /**
     * Validate the account and persona together before an identity-bearing
     * operation. Callers must use the returned entity and never trust a
     * client-supplied user_id or persona status after this point.
     *
     * @throws RuntimeException
     */
    public function assertPersonaUsableByUser(string $userId, string $personaId, int $operation = 0): Persona
    {
        $requestedOperation = PersonaOperation::tryFrom($operation);
        if ($requestedOperation === null || $requestedOperation === PersonaOperation::UNSPECIFIED) {
            throw new RuntimeException('persona operation is required', ErrorCode::PERSONA_FORBIDDEN->value);
        }

        $persona = $this->personaRepo->findById($personaId);
        if (empty($persona)
            || $persona->userId !== $userId
            || $persona->status !== 1
            || $persona->deletedAt !== null
        ) {
            throw new RuntimeException(ErrorCode::PERSONA_FORBIDDEN->message(), ErrorCode::PERSONA_FORBIDDEN->value);
        }

        $user = $this->userRepo->findById($userId);
        if (!$user || !$user->isActive()) {
            throw new RuntimeException(ErrorCode::USER_BANNED->message(), ErrorCode::USER_BANNED->value);
        }

        if ($persona->type === PersonaType::ALIAS->value && !$persona->isInternalAlias) {
            throw new RuntimeException(ErrorCode::PERSONA_FORBIDDEN->message(), ErrorCode::PERSONA_FORBIDDEN->value);
        }
        if ($persona->type === PersonaType::ALIAS->value && !$requestedOperation->allowsAlias()) {
            throw new RuntimeException(ErrorCode::PERSONA_FORBIDDEN->message(), ErrorCode::PERSONA_FORBIDDEN->value);
        }
        if ($persona->type !== PersonaType::MAIN->value && $persona->type !== PersonaType::ALIAS->value) {
            throw new RuntimeException(ErrorCode::PERSONA_FORBIDDEN->message(), ErrorCode::PERSONA_FORBIDDEN->value);
        }

        return $persona;
    }

    public function getPersona(string $personaId): Persona
    {
        $persona = $this->personaRepo->findById($personaId);
        if (empty($persona)
            || $persona->type !== PersonaType::MAIN->value
            || $persona->status !== 1
            || $persona->deletedAt !== null
        ) {
            throw new RuntimeException('Persona not found', ErrorCode::PERSONA_NOT_FOUND->value);
        }
        $user = $this->userRepo->findById($persona->userId);
        if (!$user || !$user->isActive()) {
            throw new RuntimeException(ErrorCode::PERSONA_NOT_FOUND->message(), ErrorCode::PERSONA_NOT_FOUND->value);
        }
        return $persona;
    }

    /**
     * @param array<string> $personaIds
     * @return array<Persona>
     */
    public function batchGetPersonas(array $personaIds): array
    {
        return array_values(array_filter(
            $this->personaRepo->batchFindByIds($personaIds),
            static fn (Persona $persona): bool => $persona->type === PersonaType::MAIN->value,
        ));
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
            $this->assertPersonaUsableByUser($userId, $personaId, PersonaOperation::UPDATE_PROFILE->value);
            $nickname = $nickname !== null ? $this->normalizePersonaNickname($nickname) : null;
            $avatarKey = $this->normalizePersonaNullableText(
                $avatarKey,
                self::MAX_PERSONA_AVATAR_KEY_LENGTH,
                'avatar_key',
            );
            $bio = $this->normalizePersonaNullableText($bio, self::MAX_PERSONA_BIO_LENGTH, 'bio');

            $updated = $this->personaRepo->update($personaId, $userId, $nickname, $avatarKey, $bio);
            if (empty($updated)) {
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
        if (empty($user)) {
            throw new RuntimeException('User not found', ErrorCode::USER_NOT_FOUND->value);
        }
        if (!$user->isActive()) {
            throw new RuntimeException(ErrorCode::USER_BANNED->message(), ErrorCode::USER_BANNED->value);
        }

        return array_values(array_filter(
            $this->personaRepo->findByUserId($userId),
            static fn (Persona $persona): bool =>
                $persona->type === PersonaType::MAIN->value || $persona->isInternalAlias,
        ));
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
     * 在调用方事务内一起创建用户行、默认人格及注册事件，确保手机和社交注册创建的新账户具有相同默认配置。
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
            bio: null,
            isInternalAlias: false,
        );

        $alias = $this->personaRepo->create(
            id: $this->snowflake->nextId(),
            userId: $id,
            type: PersonaType::ALIAS->value,
            nickname: '匿名用户',
            avatarKey: 'system:anonymous',
            bio: null,
            isInternalAlias: true,
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
        $this->recordEvent(
            aggregateType: 'persona',
            aggregateId: $alias->id,
            eventKey: $id,
            eventType: EventTypes::PERSONA_UPDATED,
            payload: $alias->toArray(),
        );

        return $user;
    }

    private function normalizeRegisterChannel(string $registerChannel): string
    {
        $registerChannel = trim($registerChannel);

        return filled($registerChannel) ? $registerChannel : 'default';
    }

    private function normalizeRegistrationKey(string $registrationKey): string
    {
        $registrationKey = trim($registrationKey);
        if (blank($registrationKey)) {
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
        if (blank($nickname)) {
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

    private function findMainPersona(string $userId): Persona
    {
        foreach ($this->personaRepo->findByUserId($userId) as $persona) {
            if ($persona->type === PersonaType::MAIN->value) {
                return $persona;
            }
        }

        throw new RuntimeException('Persona not found', ErrorCode::PERSONA_NOT_FOUND->value);
    }
}
