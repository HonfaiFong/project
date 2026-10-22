<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Errors\ErrorCode;
use Contracts\Events\EventTypes;
use Packages\Identity\PersonaMode;
use Packages\Identity\PersonaOperation;
use Packages\IdGenerator\Snowflake;
use Packages\Phone\PhoneNormalizer;
use Services\UserService\Application\Service\UserApplicationService;
use Services\UserService\Domain\Entity\Persona;
use Services\UserService\Domain\Entity\PersonaType;
use Services\UserService\Domain\Entity\User;
use Services\UserService\Domain\Exception\DuplicateUserException;
use Services\UserService\Domain\Repository\PersonaRepositoryInterface;
use Services\UserService\Domain\Repository\UserRepositoryInterface;
use Tests\Support\PassThroughTransactionManager;
use Tests\Support\RecordingEventOutbox;
use Tests\TestCase;

class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<string, User> */
    public array $users = [];

    /** @var array<string, string> 注册键 => 用户 ID */
    public array $socialRegistrationKeys = [];

    /** @var list<string> 软删除行占用的注册键，与唯一索引保持一致 */
    public array $softDeletedSocialRegistrationKeys = [];

    public function findById(string $id): ?User
    {
        return $this->users[$id] ?? null;
    }

    public function findByIdForUpdate(string $id): ?User
    {
        return $this->findById($id);
    }

    public function findByPhone(string $phone): ?User
    {
        foreach ($this->users as $u) {
            if ($u->phone === $phone) {
                return $u;
            }
        }
        return null;
    }

    public function findBySocialRegistrationKey(string $registrationKey): ?User
    {
        $userId = $this->socialRegistrationKeys[$registrationKey] ?? null;

        return $userId !== null ? ($this->users[$userId] ?? null) : null;
    }

    public function socialRegistrationKeyExists(string $registrationKey): bool
    {
        return isset($this->socialRegistrationKeys[$registrationKey])
            || in_array($registrationKey, $this->softDeletedSocialRegistrationKeys, true);
    }

    public function findByEmail(string $email): ?User
    {
        foreach ($this->users as $u) {
            if ($u->email === $email) {
                return $u;
            }
        }
        return null;
    }

    public function batchFindByIds(array $ids): array
    {
        $res = [];
        foreach ($ids as $id) {
            if (isset($this->users[$id])) {
                $res[] = $this->users[$id];
            }
        }
        return $res;
    }

    public function create(
        string $id,
        ?string $phone,
        ?string $email,
        string $registerChannel = 'default',
        int $riskLevel = 0,
        ?string $socialRegistrationKey = null
    ): User {
        if ($socialRegistrationKey !== null && in_array($socialRegistrationKey, $this->softDeletedSocialRegistrationKeys, true)) {
            // MySQL 的唯一索引仍保留软删除行的注册键。
            throw new DuplicateUserException('Duplicate registration key');
        }

        $u = new User(
            id: $id,
            status: 1,
            phone: $phone,
            email: $email,
            registerChannel: $registerChannel,
            riskLevel: $riskLevel
        );
        $this->users[$id] = $u;
        if ($socialRegistrationKey !== null) {
            $this->socialRegistrationKeys[$socialRegistrationKey] = $id;
        }
        return $u;
    }

    public function update(string $id, ?string $phone, ?string $email): ?User
    {
        if (isset($this->users[$id])) {
            $existing = $this->users[$id];
            $u = new User(
                id: $id,
                status: $existing->status,
                phone: $phone ?? $existing->phone,
                email: $email ?? $existing->email,
                registerChannel: $existing->registerChannel,
                riskLevel: $existing->riskLevel
            );
            $this->users[$id] = $u;
            return $u;
        }
        return null;
    }

    public function updateLastLogin(string $id): void
    {
    }
}

final class InMemoryPersonaRepository implements PersonaRepositoryInterface
{
    /** @var array<string, Persona> */
    public array $personas = [];

    public function findById(string $id): ?Persona
    {
        return $this->personas[$id] ?? null;
    }

    public function batchFindByIds(array $ids): array
    {
        $res = [];
        foreach ($ids as $id) {
            if (isset($this->personas[$id])) {
                $res[] = $this->personas[$id];
            }
        }
        return $res;
    }

    public function findByUserId(string $userId): array
    {
        $res = [];
        foreach ($this->personas as $p) {
            if ($p->userId === $userId && $p->deletedAt === null) {
                $res[] = $p;
            }
        }
        return $res;
    }

    public function findInternalAliasByUserIdForUpdate(string $userId): ?Persona
    {
        foreach ($this->personas as $persona) {
            if ($persona->userId === $userId
                && $persona->type === PersonaType::ALIAS->value
                && $persona->isInternalAlias
            ) {
                return $persona;
            }
        }

        return null;
    }

    public function countByUserId(string $userId): int
    {
        return count($this->findByUserId($userId));
    }

    public function create(
        string $id,
        string $userId,
        int $type,
        string $nickname,
        ?string $avatarKey,
        ?string $bio,
        bool $isInternalAlias = false
    ): Persona {
        $p = new Persona(
            id: $id,
            userId: $userId,
            type: $type,
            nickname: $nickname,
            avatarKey: $avatarKey,
            bio: $bio,
            isInternalAlias: $isInternalAlias,
        );
        $this->personas[$id] = $p;
        return $p;
    }

    public function update(
        string $id,
        string $userId,
        ?string $nickname,
        ?string $avatarKey,
        ?string $bio
    ): ?Persona {
        if (isset($this->personas[$id])) {
            $existing = $this->personas[$id];
            $p = new Persona(
                id: $id,
                userId: $userId,
                type: $existing->type,
                nickname: $nickname ?? $existing->nickname,
                avatarKey: $avatarKey ?? $existing->avatarKey,
                bio: $bio ?? $existing->bio
            );
            $this->personas[$id] = $p;
            return $p;
        }
        return null;
    }
}

final class UserServiceTest extends TestCase
{
    private InMemoryUserRepository $userRepo;
    private InMemoryPersonaRepository $personaRepo;
    private RecordingEventOutbox $outbox;
    private UserApplicationService $userService;

    protected function setUp(): void
    {
        $this->userRepo = new InMemoryUserRepository();
        $this->personaRepo = new InMemoryPersonaRepository();
        $this->outbox = new RecordingEventOutbox();
        $this->userService = new UserApplicationService(
            userRepo: $this->userRepo,
            personaRepo: $this->personaRepo,
            snowflake: new Snowflake(1),
            transactionManager: new PassThroughTransactionManager(),
            outbox: $this->outbox,
            phoneNormalizer: new PhoneNormalizer(),
        );
    }

    public function testFindOrCreateUserAutoCreatesDefaultPersona(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+8613800000000');

        $this->assertTrue($res['is_new_user']);
        $this->assertSame('+8613800000000', $res['user']->phone);

        $personas = $this->userService->listPersonas($res['user']->id);
        $this->assertCount(2, $personas);
        $this->assertSame($res['user']->id, $personas[0]->userId);
        $this->assertSame(PersonaType::MAIN->value, $personas[0]->type);
        $this->assertSame(PersonaType::ALIAS->value, $personas[1]->type);
        $this->assertFalse($personas[0]->isInternalAlias);
        $this->assertTrue($personas[1]->isInternalAlias);

        $userRegisteredEvents = $this->outbox->findByEventType(EventTypes::USER_REGISTERED);
        $this->assertCount(1, $userRegisteredEvents);
        $this->assertSame('user', $userRegisteredEvents[0]['aggregate_type']);
        $this->assertSame($res['user']->id, $userRegisteredEvents[0]['aggregate_id']);
        $this->assertSame($res['user']->id, $userRegisteredEvents[0]['event_key']);
        $this->assertNotSame('', $userRegisteredEvents[0]['trace_id']);
        $this->assertSame(EventTypes::USER_REGISTERED, $userRegisteredEvents[0]['event_type']);
        $this->assertSame($res['user']->id, $userRegisteredEvents[0]['payload']['id']);
        $this->assertSame('+8613800000000', $userRegisteredEvents[0]['payload']['phone']);

        $personaUpdatedEvents = $this->outbox->findByEventType(EventTypes::PERSONA_UPDATED);
        $this->assertCount(2, $personaUpdatedEvents);
        $this->assertSame('persona', $personaUpdatedEvents[0]['aggregate_type']);
        $this->assertSame($personas[0]->id, $personaUpdatedEvents[0]['aggregate_id']);
        $this->assertSame($res['user']->id, $personaUpdatedEvents[0]['event_key']);
        $this->assertSame($userRegisteredEvents[0]['trace_id'], $personaUpdatedEvents[0]['trace_id']);
        $this->assertSame(EventTypes::PERSONA_UPDATED, $personaUpdatedEvents[0]['event_type']);
        $this->assertSame($personas[0]->id, $personaUpdatedEvents[0]['payload']['id']);
        $this->assertSame($personas[1]->id, $personaUpdatedEvents[1]['payload']['id']);
    }

    public function testFindOrCreateUsesRegisterChannelOnlyForNewUsers(): void
    {
        $first = $this->userService->findOrCreateUserByPhone('+8613800000000', 'wechat');
        $second = $this->userService->findOrCreateUserByPhone('+8613800000000', 'qq');

        $this->assertTrue($first['is_new_user']);
        $this->assertFalse($second['is_new_user']);
        $this->assertSame('wechat', $first['user']->registerChannel);
        $this->assertSame('wechat', $second['user']->registerChannel);
    }

    public function testFindOrCreateRejectsInactiveUser(): void
    {
        $created = $this->userService->findOrCreateUserByPhone('13800000000');
        $existing = $created['user'];
        $this->userRepo->users[$existing->id] = new User(
            id: $existing->id,
            status: 0,
            phone: $existing->phone,
            email: $existing->email,
            registerChannel: $existing->registerChannel,
            riskLevel: $existing->riskLevel,
        );

        $this->expectExceptionCode(ErrorCode::USER_BANNED->value);
        $this->userService->findOrCreateUserByPhone('13800000000');
    }

    public function testFindOrCreateHandlesConcurrentDuplicateUser(): void
    {
        $racingRepo = new class ($this->userRepo) extends InMemoryUserRepository {
            private int $attempts = 0;

            public function __construct(private readonly InMemoryUserRepository $parent)
            {
            }

            public function findByPhone(string $phone): ?User
            {
                return $this->parent->findByPhone($phone);
            }

            public function create(
                string $id,
                ?string $phone,
                ?string $email,
                string $registerChannel = 'default',
                int $riskLevel = 0,
                ?string $socialRegistrationKey = null
            ): User {
                $this->attempts++;
                if ($this->attempts === 1) {
                    // 模拟并发事务成功插入
                    $this->parent->create('20002', $phone, $email);
                    throw new DuplicateUserException('Duplicate user');
                }
                return $this->parent->create($id, $phone, $email);
            }

            public function updateLastLogin(string $id): void
            {
            }
        };

        $service = new UserApplicationService(
            userRepo: $racingRepo,
            personaRepo: $this->personaRepo,
            snowflake: new Snowflake(1),
            transactionManager: new PassThroughTransactionManager(),
            outbox: new RecordingEventOutbox(),
            phoneNormalizer: new PhoneNormalizer(),
        );

        $res = $service->findOrCreateUserByPhone('+8613800000000');
        $this->assertFalse($res['is_new_user']);
        $this->assertSame('20002', $res['user']->id);
    }

    public function testFindOrCreateThrowsInternalServerErrorWhenDuplicateIsNotPhoneCollision(): void
    {
        $nonPhoneDuplicateRepo = new class extends InMemoryUserRepository {
            public function create(
                string $id,
                ?string $phone,
                ?string $email,
                string $registerChannel = 'default',
                int $riskLevel = 0,
                ?string $socialRegistrationKey = null
            ): User {
                // 模拟 Snowflake ID 冲突或其他数据库 1062 错误，且无法按手机号找到用户
                throw new DuplicateUserException('Duplicate key on PRIMARY', ErrorCode::USER_ALREADY_EXISTS->value);
            }
        };

        $service = new UserApplicationService(
            userRepo: $nonPhoneDuplicateRepo,
            personaRepo: $this->personaRepo,
            snowflake: new Snowflake(1),
            transactionManager: new PassThroughTransactionManager(),
            outbox: new RecordingEventOutbox(),
            phoneNormalizer: new PhoneNormalizer(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(ErrorCode::INTERNAL_SERVER_ERROR->value);
        $this->expectExceptionMessage(ErrorCode::INTERNAL_SERVER_ERROR->message());

        $service->findOrCreateUserByPhone('+8613800000000');
    }

    public function testFindOrCreateBySocialIdentityCreatesUserWithoutPhone(): void
    {
        $res = $this->userService->findOrCreateUserBySocialIdentity('social:1001', 'wechat');

        $this->assertTrue($res['is_new_user']);
        $this->assertNull($res['user']->phone);
        $this->assertSame('wechat', $res['user']->registerChannel);
        $this->assertSame($res['user']->id, $this->userRepo->socialRegistrationKeys['social:1001']);

        $personas = $this->userService->listPersonas($res['user']->id);
        $this->assertCount(2, $personas);
        $this->assertSame($res['user']->id, $personas[0]->userId);

        $userRegisteredEvents = $this->outbox->findByEventType(EventTypes::USER_REGISTERED);
        $this->assertCount(1, $userRegisteredEvents);
        $this->assertSame($res['user']->id, $userRegisteredEvents[0]['aggregate_id']);
        $this->assertSame($res['user']->id, $userRegisteredEvents[0]['event_key']);
        $this->assertSame($res['user']->id, $userRegisteredEvents[0]['payload']['id']);
        $this->assertNull($userRegisteredEvents[0]['payload']['phone']);
        $this->assertSame($personas[0]->id, $userRegisteredEvents[0]['payload']['persona_id']);

        $personaUpdatedEvents = $this->outbox->findByEventType(EventTypes::PERSONA_UPDATED);
        $this->assertCount(2, $personaUpdatedEvents);
        $this->assertSame($personas[0]->id, $personaUpdatedEvents[0]['aggregate_id']);
    }

    public function testZeroRegistrationKeyAndProfileTextRemainPresent(): void
    {
        $first = $this->userService->findOrCreateUserBySocialIdentity('0', '0');
        $retry = $this->userService->findOrCreateUserBySocialIdentity('0', 'qq');
        self::assertSame($first['user']->id, $retry['user']->id);
        self::assertFalse($retry['is_new_user']);
        self::assertSame('0', $retry['user']->registerChannel);
        $persona = $this->userService->listPersonas($first['user']->id)[0];
        $updated = $this->userService->updatePersona($persona->id, $first['user']->id, '0', '0', '0');
        self::assertSame('0', $updated->nickname);
        self::assertSame('0', $updated->avatarKey);
        self::assertSame('0', $updated->bio);
        $unchanged = $this->userService->updatePersona($persona->id, $first['user']->id);
        self::assertSame('0', $unchanged->bio);
        $cleared = $this->userService->updatePersona($persona->id, $first['user']->id, null, '', '');
        self::assertSame('', $cleared->bio);
        self::assertSame('', $cleared->avatarKey);
        self::assertSame('0', $cleared->nickname);
        self::assertCount(1, $this->userRepo->users);
    }

    public function testFindOrCreateBySocialIdentityReusesAccountForRepeatedKey(): void
    {
        $first = $this->userService->findOrCreateUserBySocialIdentity('social:1001', 'wechat');
        $second = $this->userService->findOrCreateUserBySocialIdentity('social:1001', 'qq');

        $this->assertTrue($first['is_new_user']);
        $this->assertFalse($second['is_new_user']);
        $this->assertSame($first['user']->id, $second['user']->id);
        $this->assertCount(1, $this->userRepo->users);
        $this->assertSame('wechat', $second['user']->registerChannel);
        $this->assertCount(1, $this->outbox->findByEventType(EventTypes::USER_REGISTERED));
    }

    public function testFindOrCreateBySocialIdentityRejectsInactiveUser(): void
    {
        $created = $this->userService->findOrCreateUserBySocialIdentity('social:1001');
        $existing = $created['user'];
        $this->userRepo->users[$existing->id] = new User(
            id: $existing->id,
            status: 0,
            phone: null,
            email: null,
            registerChannel: $existing->registerChannel,
            riskLevel: $existing->riskLevel,
        );

        $this->expectExceptionCode(ErrorCode::USER_BANNED->value);
        $this->userService->findOrCreateUserBySocialIdentity('social:1001');
    }

    public function testFindOrCreateBySocialIdentityReportsSoftDeletedAccountAsNotFound(): void
    {
        $this->userRepo->softDeletedSocialRegistrationKeys[] = 'social:1001';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(ErrorCode::USER_NOT_FOUND->value);
        $this->userService->findOrCreateUserBySocialIdentity('social:1001');
    }

    public function testFindOrCreateBySocialIdentityRejectsEmptyRegistrationKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_ARGUMENT->value);
        $this->userService->findOrCreateUserBySocialIdentity('   ');
    }

    public function testFindOrCreateBySocialIdentityRejectsOverlongRegistrationKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_ARGUMENT->value);
        $this->userService->findOrCreateUserBySocialIdentity('social:' . str_repeat('9', 200));
    }

    public function testFindOrCreateBySocialIdentityHandlesConcurrentDuplicateUser(): void
    {
        $racingRepo = new class ($this->userRepo) extends InMemoryUserRepository {
            private int $attempts = 0;

            public function __construct(private readonly InMemoryUserRepository $parent)
            {
            }

            public function findBySocialRegistrationKey(string $registrationKey): ?User
            {
                return $this->parent->findBySocialRegistrationKey($registrationKey);
            }

            public function create(
                string $id,
                ?string $phone,
                ?string $email,
                string $registerChannel = 'default',
                int $riskLevel = 0,
                ?string $socialRegistrationKey = null
            ): User {
                $this->attempts++;
                if ($this->attempts === 1) {
                    // 模拟并发事务成功占用唯一键
                    $this->parent->create(
                        id: '20003',
                        phone: null,
                        email: null,
                        registerChannel: $registerChannel,
                        socialRegistrationKey: $socialRegistrationKey,
                    );
                    throw new DuplicateUserException('Duplicate registration key');
                }

                return $this->parent->create($id, $phone, $email, $registerChannel, $riskLevel, $socialRegistrationKey);
            }
        };

        $service = new UserApplicationService(
            userRepo: $racingRepo,
            personaRepo: $this->personaRepo,
            snowflake: new Snowflake(1),
            transactionManager: new PassThroughTransactionManager(),
            outbox: new RecordingEventOutbox(),
            phoneNormalizer: new PhoneNormalizer(),
        );

        $res = $service->findOrCreateUserBySocialIdentity('social:1001');

        $this->assertFalse($res['is_new_user']);
        $this->assertSame('20003', $res['user']->id);
    }

    public function testUpdateUserSuccess(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+8613800000000');
        $userId = $res['user']->id;

        $updated = $this->userService->updateUser($userId, '+8613900000000', 'test@example.com');
        $this->assertSame('+8613900000000', $updated->phone);
        $this->assertSame('test@example.com', $updated->email);

        $fetched = $this->userService->getUser($userId);
        $this->assertSame('+8613900000000', $fetched->phone);
        $this->assertSame('test@example.com', $fetched->email);

        $userUpdatedEvents = $this->outbox->findByEventType(EventTypes::USER_UPDATED);
        $this->assertCount(1, $userUpdatedEvents);
        $this->assertSame('user', $userUpdatedEvents[0]['aggregate_type']);
        $this->assertSame($userId, $userUpdatedEvents[0]['aggregate_id']);
        $this->assertSame(EventTypes::USER_UPDATED, $userUpdatedEvents[0]['event_type']);
        $this->assertSame($userId, $userUpdatedEvents[0]['payload']['id']);
        $this->assertSame('+8613900000000', $userUpdatedEvents[0]['payload']['phone']);
        $this->assertSame('test@example.com', $userUpdatedEvents[0]['payload']['email']);
    }

    public function testUpdateUserNormalizesPhoneBeforePersistence(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('13800000000');

        $updated = $this->userService->updateUser($res['user']->id, '13900000000');

        $this->assertSame('+8613900000000', $updated->phone);
    }

    public function testUpdateUserNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User not found');
        $this->userService->updateUser('999999', '+8613900000000');
    }

    public function testAliasCreationIsDisabled(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+8613800000000');

        $this->expectExceptionCode(ErrorCode::PERSONA_FORBIDDEN->value);
        $this->userService->createAliasPersona($res['user']->id, '  Night Rain  ');
    }

    public function testEnsureInternalAliasIsIdempotent(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+8613800000000');

        $first = $this->userService->ensureInternalAlias($res['user']->id);
        $second = $this->userService->ensureInternalAlias($res['user']->id);

        $this->assertSame($first->id, $second->id);
        $this->assertTrue($first->isInternalAlias);
        $this->assertCount(2, $this->userService->listPersonas($res['user']->id));
    }

    public function testResolvePersonaModeAllowsMainForEveryKnownOperation(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+8613800000000');
        $main = $this->userService->listPersonas($res['user']->id)[0];

        foreach (PersonaOperation::cases() as $operation) {
            if ($operation === PersonaOperation::UNSPECIFIED) {
                continue;
            }

            $resolved = $this->userService->resolvePersonaMode(
                $res['user']->id,
                PersonaMode::MAIN->value,
                $operation->value,
            );

            $this->assertSame($main->id, $resolved->id, $operation->name);
            $this->assertFalse($resolved->isInternalAlias, $operation->name);
        }
    }

    public function testResolvePersonaModeAllowsCanonicalAliasOnlyForContentAndChat(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+8613800000000');
        $alias = $this->userService->listPersonas($res['user']->id)[1];
        $allowed = [
            PersonaOperation::CREATE_POST,
            PersonaOperation::CREATE_COMMENT,
            PersonaOperation::CREATE_CONVERSATION,
            PersonaOperation::SEND_MESSAGE,
        ];

        foreach (PersonaOperation::cases() as $operation) {
            if ($operation === PersonaOperation::UNSPECIFIED) {
                continue;
            }

            if (in_array($operation, $allowed, true)) {
                $resolved = $this->userService->resolvePersonaMode(
                    $res['user']->id,
                    PersonaMode::ALIAS->value,
                    $operation->value,
                );
                $this->assertSame($alias->id, $resolved->id, $operation->name);
                continue;
            }

            try {
                $this->userService->resolvePersonaMode(
                    $res['user']->id,
                    PersonaMode::ALIAS->value,
                    $operation->value,
                );
                $this->fail('ALIAS must be rejected for ' . $operation->name);
            } catch (\RuntimeException $exception) {
                $this->assertSame(ErrorCode::PERSONA_FORBIDDEN->value, $exception->getCode(), $operation->name);
            }
        }
    }

    public function testResolvePersonaModeRejectsUnspecifiedAndUnknownOperationsWithoutCreatingAlias(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+8613800000000');
        $alias = $this->userService->listPersonas($res['user']->id)[1];
        unset($this->personaRepo->personas[$alias->id]);
        $personaCount = count($this->personaRepo->findByUserId($res['user']->id));
        $eventCount = count($this->outbox->findByEventType(EventTypes::PERSONA_UPDATED));

        foreach ([PersonaOperation::UNSPECIFIED->value, 999] as $operation) {
            try {
                $this->userService->resolvePersonaMode(
                    $res['user']->id,
                    PersonaMode::ALIAS->value,
                    $operation,
                );
                $this->fail('Unknown operations must fail closed');
            } catch (\RuntimeException $exception) {
                $this->assertSame(ErrorCode::PERSONA_FORBIDDEN->value, $exception->getCode());
            }
        }

        $this->assertCount($personaCount, $this->personaRepo->findByUserId($res['user']->id));
        $this->assertCount($eventCount, $this->outbox->findByEventType(EventTypes::PERSONA_UPDATED));
    }

    public function testLegacyAliasCannotBeUsedForAnyAliasOperation(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+8613800000000');
        $legacy = $this->personaRepo->create(
            id: 'legacy-alias',
            userId: $res['user']->id,
            type: PersonaType::ALIAS->value,
            nickname: 'Historical alias',
            avatarKey: null,
            bio: null,
            isInternalAlias: false,
        );

        $this->expectExceptionCode(ErrorCode::PERSONA_FORBIDDEN->value);
        $this->userService->assertPersonaUsableByUser(
            $res['user']->id,
            $legacy->id,
            PersonaOperation::CREATE_POST->value,
        );
    }

    public function testSoftDeletedCanonicalAliasCannotBeRecreatedOrReactivated(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+8613800000000');
        $alias = $this->userService->listPersonas($res['user']->id)[1];
        $deleted = new Persona(
            id: $alias->id,
            userId: $alias->userId,
            type: $alias->type,
            nickname: $alias->nickname,
            avatarKey: $alias->avatarKey,
            bio: $alias->bio,
            status: $alias->status,
            isInternalAlias: true,
            deletedAt: '2026-10-10 00:00:00',
        );
        $this->personaRepo->personas[$alias->id] = $deleted;
        $beforeEvents = count($this->outbox->findByEventType(EventTypes::PERSONA_UPDATED));

        $this->expectExceptionCode(ErrorCode::PERSONA_FORBIDDEN->value);
        try {
            $this->userService->resolvePersonaMode(
                $res['user']->id,
                PersonaMode::ALIAS->value,
                PersonaOperation::CREATE_POST->value,
            );
        } finally {
            $this->assertSame($deleted, $this->personaRepo->personas[$alias->id]);
            $this->assertSame($beforeEvents, count($this->outbox->findByEventType(EventTypes::PERSONA_UPDATED)));
        }
    }

    public function testLegacyPersonaCreationRejectsMainType(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+8613800000000');

        $this->expectExceptionCode(ErrorCode::PERSONA_FORBIDDEN->value);
        $this->userService->createPersona(
            $res['user']->id,
            PersonaType::MAIN->value,
            'Should not be created',
        );
    }

    public function testLegacyZeroTypeIsDisabledWithoutSideEffects(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+8613800000000');

        $this->expectExceptionCode(ErrorCode::PERSONA_FORBIDDEN->value);
        $this->userService->createPersona($res['user']->id, 0, 'Legacy Alias');
    }

    public function testLegacyPersonaCreationRejectsUnknownType(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+8613800000000');

        $this->expectExceptionCode(ErrorCode::PERSONA_FORBIDDEN->value);
        $this->userService->createPersona($res['user']->id, 99, 'Unknown Type');
    }

    public function testDisabledPersonaCreationRejectsBeforeInputNormalization(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+8613800000000');
        $userId = $res['user']->id;

        $this->expectExceptionCode(ErrorCode::PERSONA_FORBIDDEN->value);
        $this->userService->createAliasPersona($userId, str_repeat('n', 65));
    }

    public function testPersonaValidationRejectsInactiveAccount(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+8613800000000');
        $userId = $res['user']->id;
        $personaId = $this->userService->listPersonas($userId)[0]->id;

        $this->userRepo->users[$userId] = new User(
            id: $userId,
            status: 0,
            phone: $res['user']->phone,
            email: $res['user']->email,
            registerChannel: $res['user']->registerChannel,
            riskLevel: $res['user']->riskLevel,
        );

        $this->expectExceptionCode(ErrorCode::USER_BANNED->value);
        $this->userService->assertPersonaUsableByUser(
            $userId,
            $personaId,
            PersonaOperation::UPDATE_PROFILE->value,
        );
    }

    public function testPersonaValidationRejectsInactivePersona(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+8613800000000');
        $userId = $res['user']->id;
        $persona = $this->userService->listPersonas($userId)[0];
        $this->personaRepo->personas[$persona->id] = new Persona(
            id: $persona->id,
            userId: $persona->userId,
            type: $persona->type,
            nickname: $persona->nickname,
            avatarKey: $persona->avatarKey,
            bio: $persona->bio,
            status: 0,
            isInternalAlias: $persona->isInternalAlias,
        );

        $this->expectExceptionCode(ErrorCode::PERSONA_FORBIDDEN->value);
        $this->userService->assertPersonaUsableByUser($userId, $persona->id);
    }

    public function testPersonaValidationRejectsCrossUserAndDeletedPersona(): void
    {
        $first = $this->userService->findOrCreateUserByPhone('+8613800000000');
        $second = $this->userService->findOrCreateUserByPhone('+8613900000000');
        $personaId = $this->userService->listPersonas($first['user']->id)[0]->id;

        try {
            $this->userService->assertPersonaUsableByUser(
                $second['user']->id,
                $personaId,
                PersonaOperation::UPDATE_PROFILE->value,
            );
            $this->fail('A persona must not be usable by another account');
        } catch (\RuntimeException $exception) {
            $this->assertSame(ErrorCode::PERSONA_FORBIDDEN->value, $exception->getCode());
        }

        try {
            $this->userService->assertPersonaUsableByUser(
                'missing-user',
                $personaId,
                PersonaOperation::UPDATE_PROFILE->value,
            );
            $this->fail('An unknown account must not authorize a persona');
        } catch (\RuntimeException $exception) {
            $this->assertSame(ErrorCode::PERSONA_FORBIDDEN->value, $exception->getCode());
        }

        unset($this->personaRepo->personas[$personaId]);
        $this->expectExceptionCode(ErrorCode::PERSONA_FORBIDDEN->value);
        $this->userService->assertPersonaUsableByUser(
            $first['user']->id,
            $personaId,
            PersonaOperation::UPDATE_PROFILE->value,
        );
    }

    public function testUpdateRejectsInactivePersona(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+8613800000000');
        $userId = $res['user']->id;
        $persona = $this->userService->listPersonas($userId)[0];
        $this->personaRepo->personas[$persona->id] = new Persona(
            id: $persona->id,
            userId: $persona->userId,
            type: $persona->type,
            nickname: $persona->nickname,
            avatarKey: $persona->avatarKey,
            bio: $persona->bio,
            status: 0,
            isInternalAlias: $persona->isInternalAlias,
        );

        $this->expectExceptionCode(ErrorCode::PERSONA_FORBIDDEN->value);
        $this->userService->updatePersona($persona->id, $userId, 'Inactive update');
    }

    public function testCreateAndUpdateRejectInactiveAccount(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+8613800000000');
        $userId = $res['user']->id;
        $personaId = $this->userService->listPersonas($userId)[0]->id;
        $this->userRepo->users[$userId] = new User(
            id: $userId,
            status: 0,
            phone: $res['user']->phone,
            email: $res['user']->email,
            registerChannel: $res['user']->registerChannel,
            riskLevel: $res['user']->riskLevel,
        );

        try {
            $this->userService->createAliasPersona($userId, 'Blocked alias');
            $this->fail('A blocked account must not create an alias');
        } catch (\RuntimeException $exception) {
            $this->assertSame(ErrorCode::PERSONA_FORBIDDEN->value, $exception->getCode());
        }

        $this->expectExceptionCode(ErrorCode::USER_BANNED->value);
        $this->userService->updatePersona($personaId, $userId, 'Blocked update');
    }

    public function testEnforcesPersonaOwnership(): void
    {
        $persona = $this->personaRepo->create('30001', 'user_A', 1, 'Persona A', null, null);

        $failed = false;
        try {
            $this->userService->updatePersona('30001', 'user_B', 'Hacked');
        } catch (\RuntimeException $e) {
            $failed = true;
        }

        $this->assertTrue($failed, 'Expected exception on forbidden persona modification');
    }

    public function testUpdatePersonaRecordsOutboxEvent(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+8613800000000');
        $userId = $res['user']->id;
        $personas = $this->userService->listPersonas($userId);
        $personaId = $personas[0]->id;

        $updatedPersona = $this->userService->updatePersona($personaId, $userId, 'New Nickname', 'new_avatar.jpg', 'New Bio');
        $this->assertSame('New Nickname', $updatedPersona->nickname);

        $personaEvents = $this->outbox->findByEventType(EventTypes::PERSONA_UPDATED);
        // 注册创建 MAIN + canonical ALIAS，各自产生一次；更新 MAIN 再产生一次。
        $this->assertCount(3, $personaEvents);
        $lastEvent = end($personaEvents);
        $this->assertSame($personaId, $lastEvent['aggregate_id']);
        $this->assertSame($userId, $lastEvent['event_key']);
        $this->assertSame('New Nickname', $lastEvent['payload']['nickname']);
    }
}
