<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Packages\Database\EloquentConnection;
use Packages\Outbox\Model\OutboxEvent;
use Packages\Outbox\OutboxClaim;
use Packages\Outbox\OutboxRepository;
use PDO;
use Throwable;
use RuntimeException;
use Tests\TestCase;

final class EloquentOutboxConcurrencyTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('SOCIAL_TEST_MYSQL_DATABASE');
        if (!$database) {
            $this->markTestSkipped('SOCIAL_TEST_MYSQL_DATABASE is not configured');
        }
        if (!str_starts_with($database, 'social_test_')) {
            throw new RuntimeException('An isolated social_test_ database is required');
        }
        $this->pdo = $this->connect();
        $schema = EloquentConnection::fromPdo($this->pdo)->getSchemaBuilder();
        $schema->dropIfExists('outbox_events');
        $schema->create('outbox_events', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('event_id')->unique();
            $table->string('aggregate_type');
            $table->unsignedBigInteger('aggregate_id');
            $table->string('event_key');
            $table->string('trace_id');
            $table->string('event_type');
            $table->integer('event_version');
            $table->json('payload');
            $table->integer('status');
            $table->dateTime('available_at', 3);
            $table->dateTime('claimed_at', 3)->nullable();
            $table->dateTime('lease_until', 3)->nullable();
            $table->string('claim_token', 64)->nullable();
            $table->dateTime('published_at', 3)->nullable();
            $table->dateTime('created_at', 3);
            $table->integer('attempt_count')->default(0);
            $table->index(['event_key', 'id', 'status']);
        });
        foreach (['9223372036854775808' => 'same', '9223372036854775809' => 'same', '9223372036854775810' => 'other'] as $id => $key) {
            EloquentConnection::model(OutboxEvent::class, $this->pdo)->newQuery()->create([
                'id' => $id, 'event_id' => 'event-' . $id,
                'aggregate_type' => $id === '9223372036854775808' ? 'user' : 'persona',
                'aggregate_id' => $id, 'event_key' => $key, 'trace_id' => 'trace',
                'event_type' => 'registered', 'event_version' => 1, 'payload' => '{}', 'status' => 0,
                'available_at' => '2020-01-01 00:00:00.123', 'created_at' => '2020-01-01 00:00:00.123',
            ]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            EloquentConnection::fromPdo($this->pdo)->getSchemaBuilder()->dropIfExists('outbox_events');
            EloquentConnection::forget($this->pdo);
        }
        parent::tearDown();
    }

    /** @throws Throwable */
    public function testLockedPredecessorBlocksItsKeyButOtherKeysCanBeClaimed(): void
    {
        $other = $this->connect();
        $blocker = EloquentConnection::fromPdo($other);
        $blocker->beginTransaction();
        try {
            EloquentConnection::model(OutboxEvent::class, $other)->newQuery()
                ->whereKey('9223372036854775808')->lockForUpdate()->first();
            $claimed = (new OutboxRepository($this->pdo))->claimPending();
            $this->assertSame(['9223372036854775810'], array_map(static fn (OutboxClaim $c): string => $c->record->id, $claimed));
            $this->assertSame('2020-01-01 00:00:00.123', $claimed[0]->record->createdAt);
        } finally {
            $blocker->rollBack();
            EloquentConnection::forget($other);
        }
        $repo = new OutboxRepository($this->pdo);
        $pending = $repo->claimPending();
        $this->assertSame(['9223372036854775808'], array_map(static fn (OutboxClaim $c): string => $c->record->id, $pending));
        $repo->markFailed($pending[0], 60);
        $this->assertSame([], $repo->claimPending());
    }

    /** @throws Throwable */
    public function testClaimUpdateFailureRollsBackEveryClaim(): void
    {
        $connection = EloquentConnection::fromPdo($this->pdo);
        $connection->beforeExecuting(static function (string $query): void {
            if (str_starts_with($query, 'update ')) {
                throw new RuntimeException('Claim update failed');
            }
        });
        try {
            (new OutboxRepository($this->pdo))->claimPending();
            $this->fail('A failed claim update must propagate');
        } catch (RuntimeException $exception) {
            $this->assertSame('Claim update failed', $exception->getMessage());
        }
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(3, EloquentConnection::model(OutboxEvent::class, $this->pdo)->newQuery()->where('status', 0)->count());
    }

    private function connect(): PDO
    {
        return new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('SOCIAL_TEST_MYSQL_HOST') ?: '127.0.0.1', getenv('SOCIAL_TEST_MYSQL_PORT') ?: '3306',
            getenv('SOCIAL_TEST_MYSQL_DATABASE')),
            getenv('SOCIAL_TEST_MYSQL_USER') ?: 'root', getenv('SOCIAL_TEST_MYSQL_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    }
}
