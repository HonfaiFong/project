<?php

declare(strict_types=1);

namespace Tests\Unit;

use Packages\IdGenerator\Snowflake;
use Services\UserService\Application\Service\UserApplicationService;
use Services\UserService\Domain\Entity\Persona;
use Services\UserService\Domain\Entity\User;
use Services\UserService\Domain\Repository\PersonaRepositoryInterface;
use Services\UserService\Domain\Repository\UserRepositoryInterface;
use Tests\TestCase;

final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<string, User> */
    public array $users = [];

    public function findById(string $id): ?User
    {
        return $this->users[$id] ?? null;
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
    private UserApplicationService $userService;

    public function setUp(): void
    {
        $this->userRepo = new InMemoryUserRepository();
        $this->personaRepo = new InMemoryPersonaRepository();
        $this->userService = new UserApplicationService(
            userRepo: $this->userRepo,
            personaRepo: $this->personaRepo,
            snowflake: new Snowflake(1)
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
}
