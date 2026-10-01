<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contracts\Events\EventTypes;
use Packages\IdGenerator\Snowflake;
use Packages\Outbox\OutboxRepository;
use Packages\Phone\PhoneNormalizer;
use PDO;
use PDOException;
use Services\UserService\Application\Service\UserApplicationService;
use Services\UserService\Infrastructure\Repository\PdoPersonaRepository;
use Services\UserService\Infrastructure\Repository\PdoTransactionManager;
use Services\UserService\Infrastructure\Repository\PdoUserRepository;
use Tests\TestCase;

final class UserOutboxTransactionTest extends TestCase
{
    private PDO $pdo;
    private UserApplicationService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE users (
            id TEXT PRIMARY KEY, status INTEGER NOT NULL, phone TEXT UNIQUE, email TEXT,
            register_channel TEXT NOT NULL, risk_level INTEGER NOT NULL,
            last_login_at TEXT, last_active_at TEXT, created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL, deleted_at TEXT
        )');
        $this->pdo->exec('CREATE TABLE personas (
            id TEXT PRIMARY KEY, user_id TEXT NOT NULL, type INTEGER NOT NULL,
            nickname TEXT NOT NULL, avatar_key TEXT, bio TEXT, status INTEGER NOT NULL,
            created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT
        )');
        $this->pdo->exec('CREATE TABLE outbox_events (
            id TEXT PRIMARY KEY, event_id TEXT NOT NULL UNIQUE,
            aggregate_type TEXT NOT NULL, aggregate_id TEXT NOT NULL,
            event_key TEXT NOT NULL, trace_id TEXT NOT NULL,
            event_type TEXT NOT NULL, event_version INTEGER NOT NULL,
            payload TEXT NOT NULL, status INTEGER NOT NULL,
            available_at TEXT NOT NULL, created_at TEXT NOT NULL
        )');

        $snowflake = new Snowflake(1);
        $this->service = new UserApplicationService(
            userRepo: new PdoUserRepository($this->pdo),
            personaRepo: new PdoPersonaRepository($this->pdo),
            snowflake: $snowflake,
            transactionManager: new PdoTransactionManager($this->pdo),
            outbox: new OutboxRepository($this->pdo, $snowflake),
            phoneNormalizer: new PhoneNormalizer(),
        );
    }

    public function testRegistrationCommitsUserPersonaAndOutboxEvents(): void
    {
        $result = $this->service->findOrCreateUserByPhone('+8613800000000');
        $userId = $result['user']->id;

        $this->assertTrue($result['is_new_user']);
        $this->assertSame($userId, $this->pdo->query('SELECT id FROM users')->fetchColumn());
        $this->assertSame($userId, $this->pdo->query('SELECT user_id FROM personas')->fetchColumn());
        $events = $this->pdo->query('SELECT aggregate_type, aggregate_id, event_key, trace_id, event_type, payload FROM outbox_events')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $events);
        $eventsByType = array_column($events, null, 'event_type');
        $personaEvent = $eventsByType[EventTypes::PERSONA_UPDATED];
        $userEvent = $eventsByType[EventTypes::USER_REGISTERED];
        $this->assertSame('persona', $personaEvent['aggregate_type']);
        $this->assertSame($this->pdo->query('SELECT id FROM personas')->fetchColumn(), $personaEvent['aggregate_id']);
        $this->assertSame($userId, json_decode($personaEvent['payload'], true, flags: JSON_THROW_ON_ERROR)['user_id']);
        $this->assertSame('user', $userEvent['aggregate_type']);
        $this->assertSame($userId, $userEvent['aggregate_id']);
        $this->assertSame($userId, $userEvent['event_key']);
        $this->assertSame($userId, $personaEvent['event_key']);
        $this->assertNotSame('', $userEvent['trace_id']);
        $this->assertSame($userEvent['trace_id'], $personaEvent['trace_id']);
        $this->assertSame($userId, json_decode($userEvent['payload'], true, flags: JSON_THROW_ON_ERROR)['id']);
    }

    public function testOutboxInsertFailureRollsBackUserAndPersona(): void
    {
        $this->pdo->exec("CREATE TRIGGER reject_outbox_insert BEFORE INSERT ON outbox_events
            BEGIN SELECT RAISE(FAIL, 'outbox insert failed'); END");

        try {
            $this->service->findOrCreateUserByPhone('+8613800000000');
            $this->fail('Expected the outbox insert to fail');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('outbox insert failed', $exception->getMessage());
        }

        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM personas')->fetchColumn());
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM outbox_events')->fetchColumn());
    }
}
