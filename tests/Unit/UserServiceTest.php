<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Errors\ErrorCode;
use Contracts\Events\EventTypes;
use Packages\IdGenerator\Snowflake;
use Services\UserService\Application\Service\UserApplicationService;
use Services\UserService\Domain\Entity\Persona;
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
        int $riskLevel = 0
    ): User {
        $u = new User(
            id: $id,
            status: 1,
            phone: $phone,
            email: $email,
            registerChannel: $registerChannel,
            riskLevel: $riskLevel
        );
        $this->users[$id] = $u;
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
            if ($p->userId === $userId) {
                $res[] = $p;
            }
        }
        return $res;
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
        ?string $bio
    ): Persona {
        $p = new Persona(
            id: $id,
            userId: $userId,
            type: $type,
            nickname: $nickname,
            avatarKey: $avatarKey,
            bio: $bio
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
        );
    }

    public function testFindOrCreateUserAutoCreatesDefaultPersona(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+1234567890');

        $this->assertTrue($res['is_new_user']);
        $this->assertSame('+1234567890', $res['user']->phone);

        $personas = $this->userService->listPersonas($res['user']->id);
        $this->assertNotEmpty($personas);
        $this->assertSame($res['user']->id, $personas[0]->userId);

        $userRegisteredEvents = $this->outbox->findByEventType(EventTypes::USER_REGISTERED);
        $this->assertCount(1, $userRegisteredEvents);
        $this->assertSame('user', $userRegisteredEvents[0]['aggregate_type']);
        $this->assertSame($res['user']->id, $userRegisteredEvents[0]['aggregate_id']);
        $this->assertSame('+1234567890', $userRegisteredEvents[0]['payload']['phone']);

        $personaUpdatedEvents = $this->outbox->findByEventType(EventTypes::PERSONA_UPDATED);
        $this->assertCount(1, $personaUpdatedEvents);
        $this->assertSame('persona', $personaUpdatedEvents[0]['aggregate_type']);
        $this->assertSame($personas[0]->id, $personaUpdatedEvents[0]['aggregate_id']);
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
                int $riskLevel = 0
            ): User {
                $this->attempts++;
                if ($this->attempts === 1) {
                    // Simulate concurrent transaction winning the insert
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
        );

        $res = $service->findOrCreateUserByPhone('+19999999999');
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
                int $riskLevel = 0
            ): User {
                // Simulates Snowflake ID collision or other DB 1062 where user is NOT found by phone
                throw new DuplicateUserException('Duplicate key on PRIMARY', ErrorCode::USER_ALREADY_EXISTS->value);
            }
        };

        $service = new UserApplicationService(
            userRepo: $nonPhoneDuplicateRepo,
            personaRepo: $this->personaRepo,
            snowflake: new Snowflake(1),
            transactionManager: new PassThroughTransactionManager(),
            outbox: new RecordingEventOutbox(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(ErrorCode::INTERNAL_SERVER_ERROR->value);
        $this->expectExceptionMessage(ErrorCode::INTERNAL_SERVER_ERROR->message());

        $service->findOrCreateUserByPhone('+18888888888');
    }

    public function testUpdateUserSuccess(): void
    {
        $res = $this->userService->findOrCreateUserByPhone('+1234567890');
        $userId = $res['user']->id;

        $updated = $this->userService->updateUser($userId, '+19876543210', 'test@example.com');
        $this->assertSame('+19876543210', $updated->phone);
        $this->assertSame('test@example.com', $updated->email);

        $fetched = $this->userService->getUser($userId);
        $this->assertSame('+19876543210', $fetched->phone);
        $this->assertSame('test@example.com', $fetched->email);

        $userUpdatedEvents = $this->outbox->findByEventType(EventTypes::USER_UPDATED);
        $this->assertCount(1, $userUpdatedEvents);
        $this->assertSame('user', $userUpdatedEvents[0]['aggregate_type']);
        $this->assertSame($userId, $userUpdatedEvents[0]['aggregate_id']);
        $this->assertSame('+19876543210', $userUpdatedEvents[0]['payload']['phone']);
        $this->assertSame('test@example.com', $userUpdatedEvents[0]['payload']['email']);
    }

    public function testUpdateUserNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User not found');
        $this->userService->updateUser('999999', '+10000000000');
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
        $res = $this->userService->findOrCreateUserByPhone('+1234567890');
        $userId = $res['user']->id;
        $personas = $this->userService->listPersonas($userId);
        $personaId = $personas[0]->id;

        $updatedPersona = $this->userService->updatePersona($personaId, $userId, 'New Nickname', 'new_avatar.jpg', 'New Bio');
        $this->assertSame('New Nickname', $updatedPersona->nickname);

        $personaEvents = $this->outbox->findByEventType(EventTypes::PERSONA_UPDATED);
        // 1 from auto-created default persona + 1 from updatePersona
        $this->assertCount(2, $personaEvents);
        $lastEvent = end($personaEvents);
        $this->assertSame($personaId, $lastEvent['aggregate_id']);
        $this->assertSame('New Nickname', $lastEvent['payload']['nickname']);
    }
}
