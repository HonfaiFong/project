<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Packages\Database\EloquentConnection;
use Packages\Outbox\Model\OutboxEvent;
use Packages\Outbox\OutboxRepository;
use PDO;
use Exception;
use Throwable;
use PDOException;
use PDOStatement;
use Illuminate\Database\QueryException;
use WeakReference;
use Tests\TestCase;

final class EloquentPersistenceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        EloquentConnection::fromPdo($this->pdo)->getSchemaBuilder()->create('outbox_events', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('event_id')->unique();
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->string('event_key');
            $table->string('trace_id');
            $table->string('event_type');
            $table->integer('event_version');
            $table->text('payload');
            $table->integer('status');
            $table->string('available_at');
            $table->string('claimed_at')->nullable();
            $table->string('published_at')->nullable();
            $table->string('created_at');
        });
    }

    protected function tearDown(): void
    {
        EloquentConnection::forget($this->pdo);
        parent::tearDown();
    }

    /** @throws Exception */
    public function testConnectionsStayIsolatedAndModelsShareTheirPdoTransaction(): void
    {
        $connection = EloquentConnection::fromPdo($this->pdo);
        $this->assertSame($connection, EloquentConnection::fromPdo($this->pdo));
        $other = new PDO('sqlite::memory:');
        $this->assertNotSame($connection, EloquentConnection::fromPdo($other));
        EloquentConnection::forget($other);
        $model = EloquentConnection::model(OutboxEvent::class, $this->pdo);
        $this->assertSame($connection, $model->getConnection());

        $this->pdo->beginTransaction();
        (new OutboxRepository($this->pdo))->recordEvent('event', 'user', '1001', 'registered', 1, [], '1001', 'trace');
        $this->assertSame(1, $model->newQuery()->count());
        $this->pdo->rollBack();
        $this->assertSame(0, $model->newQuery()->count());
    }

    /** @throws Exception */
    public function testWarmupRunsARealQueryPerModelClassWithoutTouchingRows(): void
    {
        EloquentConnection::warmup($this->pdo, OutboxEvent::class);
        $this->assertSame(0, EloquentConnection::fromPdo($this->pdo)->table('outbox_events')->count());

        // 对已初始化模型重复预热，应保持低开销且安全。
        EloquentConnection::warmup($this->pdo, OutboxEvent::class);
        $this->assertSame(0, EloquentConnection::fromPdo($this->pdo)->table('outbox_events')->count());
    }

    public function testDisconnectedPdoPreservesTheFailureWithoutReplayingTheWrite(): void
    {
        $pdo = new class extends PDO {
            public int $attempts = 0;

            public function __construct()
            {
                parent::__construct('sqlite::memory:');
            }

            public function getAttribute(int $attribute): string
            {
                return 'mysql';
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                $this->attempts++;
                throw new PDOException('MySQL server has gone away', 2006);
            }
        };
        try {
            EloquentConnection::fromPdo($pdo)->table('events')->insert(['id' => '1001']);
            $this->fail('A disconnected PDO must fail');
        } catch (QueryException $exception) {
            $this->assertInstanceOf(PDOException::class, $exception->getPrevious());
            $this->assertSame(2006, $exception->getPrevious()->getCode());
            $this->assertStringContainsString('MySQL server has gone away', $exception->getPrevious()->getMessage());
            $this->assertSame(1, $pdo->attempts);
        }
        EloquentConnection::forget($pdo);
    }

    public function testStoppedConnectionsCanBeReleased(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $reference = WeakReference::create($pdo);
        EloquentConnection::fromPdo($pdo);
        EloquentConnection::forget($pdo);
        unset($pdo);
        gc_collect_cycles();
        $this->assertNull($reference->get());
    }

    /** @throws Throwable */
    public function testClaimsRespectEventKeyOrderingAndRecoverFailedEvents(): void
    {
        $model = EloquentConnection::model(OutboxEvent::class, $this->pdo);
        foreach (['1' => 'same-key', '2' => 'same-key', '3' => 'other-key'] as $id => $key) {
            $model->newQuery()->create([
                'id' => $id, 'event_id' => 'event-' . $id,
                'aggregate_type' => 'user', 'aggregate_id' => $id,
                'event_key' => $key, 'trace_id' => 'trace', 'event_type' => 'registered',
                'event_version' => 1, 'payload' => '{}', 'status' => 0,
                'available_at' => '2020-01-01 00:00:00', 'created_at' => '2020-01-01 00:00:00',
            ]);
        }
        $repo = new OutboxRepository($this->pdo);
        $this->assertSame(['1', '3'], array_column($repo->claimPending(), 'id'));
        $this->assertSame([], $repo->claimPending());
        $repo->markFailed('1', 0);
        $this->assertSame(['1'], array_column($repo->claimPending(), 'id'));
        $repo->markPublished('1');
        $this->assertSame(['2'], array_column($repo->claimPending(), 'id'));

        $model->newQuery()->whereIn('id', ['2', '3'])->update(['claimed_at' => '2020-01-01 00:00:00']);
        $this->assertSame(2, $repo->releaseStaleClaims());
        $this->assertSame(['2', '3'], array_column($repo->claimPending(2), 'id'));
        $this->assertFalse($this->pdo->inTransaction());
    }
}
