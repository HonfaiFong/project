<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Packages\Database\EloquentConnection;
use Packages\IdGenerator\Snowflake;
use Packages\Outbox\OutboxRepository;
use PDO;
use PDOException;
use RuntimeException;
use Services\SocialService\Application\Service\SocialApplicationService;
use Services\SocialService\Infrastructure\Repository\PdoSocialRepository;
use Tests\Support\FakePersonaDirectory;
use Tests\Support\FakeResourceAccess;
use Tests\TestCase;
use Throwable;

/**
 * 真实 MySQL 并发点赞：同一真身对同一资源的并发 like 必须收敛为 1 条 likes 记录与 1 条
 * like.created 事件。并发防护由 (persona_id, target_type, target_id) 唯一键承担，事件只由
 * 真正插入成功的那一次触发。
 *
 * 连接显式启用 PDO::MYSQL_ATTR_FOUND_ROWS：该模式下“已存在行”也报告受影响行数为 1，如果
 * 实现用 rowCount() 区分“插入/已存在”就会产生重复事件，本测试据此守护该修复。
 */
final class SocialLikeConcurrencyTest extends TestCase
{
    private const WORKERS = 4;

    private PDO $pdo;

    private string $database;

    protected function setUp(): void
    {
        $database = getenv('SOCIAL_TEST_MYSQL_DATABASE');
        if ($database === false || $database === '') {
            $this->markTestSkipped('SOCIAL_TEST_MYSQL_DATABASE is not configured');
        }
        if (!str_starts_with($database, 'social_test_')) {
            throw new RuntimeException('SOCIAL_TEST_MYSQL_DATABASE must use the social_test_ prefix');
        }
        $this->database = $database;

        try {
            $this->pdo = $this->connect();
        } catch (Throwable $e) {
            $this->markTestSkipped('MySQL integration service is not available: ' . $e->getMessage());
        }

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        $schema = EloquentConnection::fromPdo($this->pdo)->getSchemaBuilder();
        foreach (['outbox_events', 'blocks', 'likes'] as $table) {
            $schema->dropIfExists($table);
        }
        EloquentConnection::forget($this->pdo);
    }

    public function testConcurrentLikesFromTheSamePersonaCreateOneRowAndOneEvent(): void
    {
        $results = $this->likeConcurrently('10', '100', 1, '900');

        foreach ($results as $result) {
            $this->assertSame('ok|1', $result, implode(', ', $results));
        }

        $this->assertSame(1, $this->likeCount('100', 1, '900'));
        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame('10', $events[0]['event_key']);
        $this->assertSame('900', $events[0]['aggregate_id']);
        $payload = json_decode((string)$events[0]['payload'], true);
        $this->assertSame('100', is_array($payload) ? ($payload['persona_id'] ?? null) : null);

        // 事件产生后重放同一 like：应用层返回幂等成功，但不得新增 like 或事件。
        $snowflake = new Snowflake(1);
        $service = new SocialApplicationService(
            new PdoSocialRepository($this->pdo),
            new FakePersonaDirectory(),
            new FakeResourceAccess(),
            $snowflake,
            new OutboxRepository($this->pdo, $snowflake),
        );
        $this->assertTrue($service->like('10', '100', 1, '900'));
        $this->assertSame(1, $this->likeCount('100', 1, '900'));
        $this->assertCount(1, $this->events());
    }

    public function testInsertLikeReportsOnlyRealInsertsAndPropagatesOtherErrors(): void
    {
        $first = new PdoSocialRepository($this->pdo);
        $second = new PdoSocialRepository($this->connect());

        $this->assertTrue($first->insertLike('1', '100', 1, '901'));
        $this->assertFalse($second->insertLike('2', '100', 1, '901'));
        $this->assertFalse($first->insertLike('3', '100', 1, '901'));
        $this->assertTrue($second->hasLike('100', 1, '901'));
        $this->assertSame(1, $this->likeCount('100', 1, '901'));
        $this->assertSame([], $this->events());

        try {
            // target_type=3 违反 CHECK 约束：非 1062 的约束错误必须原样抛出，而不是被当成“已存在”。
            $second->insertLike('4', '100', 3, '902');
            $this->fail('A CHECK constraint violation must not be reported as an existing like');
        } catch (PDOException $exception) {
            $this->assertSame(3819, (int)($exception->errorInfo[1] ?? 0));
        }
        $this->assertSame(0, $this->likeCount('100', 3, '902'));
    }

    public function testPrimaryKeyCollisionForAnotherTargetIsNotSwallowed(): void
    {
        $repository = new PdoSocialRepository($this->pdo);

        $this->assertTrue($repository->insertLike('5', '100', 1, '903'));

        try {
            // 同一主键 id 用于不同 target：冲突来自主键而不是业务唯一键，必须原样抛出。
            $repository->insertLike('5', '100', 1, '904');
            $this->fail('A primary key collision must not be reported as an existing like');
        } catch (PDOException $exception) {
            $this->assertSame(1062, (int)($exception->errorInfo[1] ?? 0));
        }

        $this->assertSame(1, $this->likeCount('100', 1, '903'));
        $this->assertSame(0, $this->likeCount('100', 1, '904'));
    }

    /** @return list<string> */
    private function likeConcurrently(string $userId, string $personaId, int $targetType, string $targetId): array
    {
        $prefix = tempnam(sys_get_temp_dir(), 'social-like-');
        if ($prefix === false) {
            $this->fail('Unable to create a concurrency coordination path');
        }
        unlink($prefix);

        $startPath = $prefix . '-start';
        $readyPaths = [];
        $resultPaths = [];
        /** @var list<array{process: resource, pipes: array<int, resource>}> $workers */
        $workers = [];

        try {
            for ($index = 0; $index < self::WORKERS; $index++) {
                $readyPaths[$index] = $prefix . '-' . $index . '-ready';
                $resultPaths[$index] = $prefix . '-' . $index . '-result';
                $pipes = [];
                $process = proc_open(
                    [
                        PHP_BINARY,
                        __DIR__ . '/fixtures/SocialLikeWorker.php',
                        $readyPaths[$index],
                        $resultPaths[$index],
                        $startPath,
                        $userId,
                        $personaId,
                        (string)$targetType,
                        $targetId,
                        (string)($index + 1),
                    ],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2),
                    null,
                    ['bypass_shell' => true],
                );
                if (!is_resource($process)) {
                    throw new RuntimeException('Unable to start a concurrent like worker');
                }
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $workers[] = ['process' => $process, 'pipes' => $pipes];
            }

            $this->waitForPath($readyPaths);
            file_put_contents($startPath, 'start');

            return $this->collectResults($workers, $resultPaths);
        } finally {
            // 屏障失败时也必须释放并回收每个工作进程，随后才能删除协调文件。
            file_put_contents($startPath, 'start');
            $this->stopWorkers($workers);
            foreach (array_merge($readyPaths, $resultPaths, [$startPath]) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /**
     * @param array<int, string> $readyPaths
     */
    private function waitForPath(array $readyPaths): void
    {
        $deadline = microtime(true) + 10.0;
        foreach ($readyPaths as $path) {
            while (!is_file($path) && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (!is_file($path)) {
                throw new RuntimeException('Timed out waiting for a like worker');
            }
        }
    }

    /**
     * @param list<array{process: resource, pipes: array<int, resource>}> $workers
     * @param array<int, string> $resultPaths
     * @return list<string>
     */
    private function collectResults(array $workers, array $resultPaths): array
    {
        $deadline = microtime(true) + 30.0;
        $results = [];
        foreach ($workers as $index => $worker) {
            $status = proc_get_status($worker['process']);
            while ($status['running'] && microtime(true) < $deadline) {
                usleep(10000);
                $status = proc_get_status($worker['process']);
            }
            if ($status['running']) {
                proc_terminate($worker['process'], 9);
                throw new RuntimeException('A like worker did not finish in time');
            }

            $results[] = is_file($resultPaths[$index])
                ? trim((string)file_get_contents($resultPaths[$index]))
                : 'missing';
        }

        return $results;
    }

    /**
     * @param list<array{process: resource, pipes: array<int, resource>}> $workers
     */
    private function stopWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            $status = proc_get_status($worker['process']);
            if ($status['running']) {
                proc_terminate($worker['process'], 9);
            }
            stream_get_contents($worker['pipes'][1]);
            stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            proc_close($worker['process']);
        }
    }

    private function likeCount(string $personaId, int $targetType, string $targetId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM `likes` WHERE `persona_id` = :persona_id '
            . 'AND `target_type` = :target_type AND `target_id` = :target_id',
        );
        $statement->execute([
            'persona_id' => $personaId,
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]);

        return (int)$statement->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT `event_key`, `aggregate_id`, `payload` FROM `outbox_events` '
            . "WHERE `event_type` = 'like.created.v1' ORDER BY `id`",
        );
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function createSchema(): void
    {
        $schema = EloquentConnection::fromPdo($this->pdo)->getSchemaBuilder();
        foreach (['outbox_events', 'blocks', 'likes'] as $table) {
            $schema->dropIfExists($table);
        }

        $schema->create('likes', static function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('persona_id');
            $table->smallInteger('target_type');
            $table->unsignedBigInteger('target_id');
            $table->dateTime('created_at', 3);
            $table->unique(['persona_id', 'target_type', 'target_id'], 'uniq_persona_target');
            $table->index(['target_type', 'target_id', 'created_at'], 'idx_target_created');
        });
        // 与迁移定义一致：target_type 仅允许 1/2，用于验证同类 23000 约束违规不会被当作“已存在”吞掉。
        $this->pdo->exec(
            'ALTER TABLE `likes` ADD CONSTRAINT `chk_like_target_type` CHECK (`target_type` IN (1, 2))',
        );

        $schema->create('blocks', static function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('blocker_user_id');
            $table->unsignedBigInteger('blocked_user_id');
            $table->dateTime('created_at', 3);
            $table->unique(['blocker_user_id', 'blocked_user_id'], 'uniq_block');
        });

        $schema->create('outbox_events', static function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->unsignedBigInteger('id')->primary();
            $table->string('event_id', 36)->unique('uniq_event_id');
            $table->string('aggregate_type', 64);
            $table->string('aggregate_id', 64);
            $table->string('event_key', 64)->default('');
            $table->string('trace_id', 64)->default('');
            $table->string('event_type', 128);
            $table->integer('event_version')->default(1);
            $table->json('payload');
            $table->smallInteger('status')->default(0);
            $table->dateTime('available_at', 3);
            $table->dateTime('claimed_at', 3)->nullable();
            $table->dateTime('published_at', 3)->nullable();
            $table->dateTime('created_at', 3);
            $table->index(['status', 'available_at'], 'idx_status_available');
            $table->index(['aggregate_type', 'aggregate_id'], 'idx_aggregate');
        });
    }

    private function connect(): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                getenv('SOCIAL_TEST_MYSQL_HOST') ?: '127.0.0.1',
                (int)(getenv('SOCIAL_TEST_MYSQL_PORT') ?: 3306),
                $this->database,
            ),
            getenv('SOCIAL_TEST_MYSQL_USER') ?: 'root',
            getenv('SOCIAL_TEST_MYSQL_PASSWORD') ?: '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
                // 评审要求：此模式下“已存在行”也报告受影响行数为 1；新增实现不得用 rowCount() 判定是否真正插入。
                PDO::MYSQL_ATTR_FOUND_ROWS => true,
            ],
        );
    }
}
