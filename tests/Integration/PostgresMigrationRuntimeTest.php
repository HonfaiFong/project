<?php

declare(strict_types=1);

namespace Tests\Integration;

use Packages\Database\PostgresConnectionFactory;
use Packages\Database\EloquentConnection;
use Packages\Outbox\OutboxRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Services\ChatService\Infrastructure\PdoChatRepository;

final class PostgresMigrationRuntimeTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (getenv('DATABASE_INTEGRATION') !== '1') {
            self::markTestSkipped('DATABASE_INTEGRATION=1 is required');
        }
        self::assertStringStartsWith('test_', (string)getenv('PG_DATABASE'), 'Use a disposable test_* database');
        $this->pdo = PostgresConnectionFactory::fromEnvironment(['PG_SCHEMA' => 'chat_db']);
        EloquentConnection::fromPdo($this->pdo)->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            EloquentConnection::fromPdo($this->pdo)->rollBack();
        }
    }

    public function testTimestampCursorRoundTripsWithTimezoneAndEqualTimestamps(): void
    {
        $this->pdo->exec("SET LOCAL TIME ZONE 'Asia/Shanghai'");
        foreach ([701, 702, 703] as $id) {
            $this->pdo->exec("INSERT INTO conversations (id,created_at,updated_at) VALUES ({$id},'2026-10-20 00:00:00.123+00','2026-10-20 00:00:00.123+00')");
            $this->pdo->exec("INSERT INTO conversation_members (id,conversation_id,user_id,persona_id,joined_at) VALUES ({$id},{$id},100,200,CURRENT_TIMESTAMP)");
        }
        $repo = new PdoChatRepository($this->pdo);
        $page = $repo->listForUser('100', 1);
        self::assertSame('703', (string)$page[0]['id']);
        $cursor = $repo->listCursor($page[0]);
        self::assertSame('2026-10-20 00:00:00.123', json_decode((string)base64_decode($cursor), true)['at']);
        $page = $repo->listForUser('100', 1, $cursor);
        self::assertSame('702', (string)$page[0]['id']);
        $page = $repo->listForUser('100', 1, $repo->listCursor($page[0]));
        self::assertSame('701', (string)$page[0]['id']);
    }

    public function testRolesCannotReadOtherServicesOrRunDdl(): void
    {
        foreach (['auth', 'user', 'content', 'social', 'chat'] as $service) {
            $role = $service . '_runtime';
            $schema = $service . '_db';
            self::assertFalse($this->boolean("SELECT has_schema_privilege('{$role}','{$schema}','CREATE')"));
            $other = $service === 'auth' ? 'user_db.users' : 'auth_db.devices';
            self::assertFalse($this->boolean("SELECT has_table_privilege('{$role}','{$other}','SELECT')"));
        }
        self::assertTrue($this->boolean("SELECT has_table_privilege('user_outbox_relay','user_db.outbox_events','SELECT,UPDATE')"));
        self::assertFalse($this->boolean("SELECT has_table_privilege('user_outbox_relay','user_db.users','SELECT')"));
    }

    public function testUniqueViolationAndSavepointRecoveryOnPostgres(): void
    {
        $this->pdo->exec("INSERT INTO user_db.users (id,email,created_at,updated_at) VALUES (701,'Test@example.com',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
        $this->pdo->exec('SAVEPOINT duplicate_email');
        try {
            $this->pdo->exec("INSERT INTO user_db.users (id,email,created_at,updated_at) VALUES (702,'test@example.com',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
            self::fail('Email uniqueness must be case insensitive');
        } catch (PDOException $exception) {
            self::assertSame('23505', $exception->getCode());
            $this->pdo->exec('ROLLBACK TO SAVEPOINT duplicate_email');
        }
        self::assertSame(1, (int)$this->pdo->query('SELECT count(*) FROM user_db.users WHERE id=701')->fetchColumn());
    }

    public function testOutboxClaimFencesOldWorkerAfterLeaseExpiry(): void
    {
        $this->pdo->exec('SET LOCAL search_path TO user_db');
        $repo = new OutboxRepository($this->pdo);
        $repo->recordEvent('pg-review-event', 'user', '701', 'user.registered.v1', 1, ['user_id' => '701'], '701', '');
        $first = $repo->claimPending(1);
        self::assertCount(1, $first);
        $this->pdo->exec("UPDATE outbox_events SET lease_until = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE event_id='pg-review-event'");
        self::assertSame(1, $repo->releaseStaleClaims());
        $replacement = $repo->claimPending(1);
        self::assertCount(1, $replacement);
        self::assertFalse($repo->markPublished($first[0]));
        self::assertTrue($repo->markPublished($replacement[0]));
    }

    private function boolean(string $sql): bool
    {
        return in_array($this->pdo->query($sql)->fetchColumn(), [true, 't', '1', 1], true);
    }
}
