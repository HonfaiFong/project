<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contracts\Errors\ErrorCode;
use Contracts\Events\EventTypes;
use Exception;
use Illuminate\Database\Schema\Blueprint;
use JsonException;
use Packages\Database\EloquentConnection;
use Packages\IdGenerator\Snowflake;
use Packages\Outbox\Model\OutboxEvent;
use Packages\Outbox\OutboxRepository;
use Packages\Phone\PhoneNormalizer;
use PDO;
use RuntimeException;
use Services\UserService\Application\Service\UserApplicationService;
use Services\UserService\Infrastructure\Model\PersonaModel;
use Services\UserService\Infrastructure\Model\UserModel;
use Services\UserService\Infrastructure\Repository\EloquentPersonaRepository;
use Services\UserService\Infrastructure\Repository\EloquentTransactionManager;
use Services\UserService\Infrastructure\Repository\EloquentUserRepository;
use Tests\TestCase;
use Throwable;

/**
 * 真实 MySQL 社交注册调用链：用户、默认人格及 Outbox 事件在一个事务内写入；使用相同注册键的并发或重复调用须收敛
 * 为单一账户。
 *
 * 测试表集合与迁移后的 user_db 表结构一致，断言针对服务在生产中使用的相同 DDL 结构执行。
 */
final class UserSocialRegistrationConcurrencyTest extends TestCase
{
    private const WORKERS = 4;

    private PDO $pdo;

    private string $database;

    private UserModel $users;

    private PersonaModel $personas;

    private OutboxEvent $outbox;

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

        $this->users = EloquentConnection::model(UserModel::class, $this->pdo);
        $this->personas = EloquentConnection::model(PersonaModel::class, $this->pdo);
        $this->outbox = EloquentConnection::model(OutboxEvent::class, $this->pdo);

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        $schema = EloquentConnection::fromPdo($this->pdo)->getSchemaBuilder();
        foreach (['outbox_events', 'personas', 'users'] as $table) {
            $schema->dropIfExists($table);
        }
        EloquentConnection::forget($this->pdo);
    }

    /** @throws Exception */
    public function testConcurrentRegistrationsWithOneKeyCreateOneAccountChain(): void
    {
        $registrationKey = 'social:9000' . random_int(100000, 999999);
        // 工作进程在 INSERT users 之前等待，直至全部到达；须由唯一键而非预检查读取来串行化。
        $results = $this->registerConcurrently($registrationKey);

        $userIds = [];
        $newUserVotes = 0;
        foreach ($results as $result) {
            $this->assertStringStartsWith('ok|', $result, implode(', ', $results));
            [, $userId, $isNewUser] = explode('|', $result, 3);
            $userIds[] = $userId;
            $newUserVotes += $isNewUser === '1' ? 1 : 0;
        }

        $this->assertCount(1, array_unique($userIds), implode(', ', $results));
        $this->assertSame(1, $newUserVotes, 'Exactly one worker may report a new account');
        $this->assertAccountChainIsComplete($userIds[0], $registrationKey);
    }

    /** @throws Exception */
    public function testRepeatedRegistrationReusesTheSameAccountChain(): void
    {
        $registrationKey = 'social:9001' . random_int(100000, 999999);
        $service = $this->service();

        $first = $service->findOrCreateUserBySocialIdentity($registrationKey, 'wechat');
        $second = $service->findOrCreateUserBySocialIdentity($registrationKey, 'qq');

        $this->assertTrue($first['is_new_user']);
        $this->assertFalse($second['is_new_user']);
        $this->assertSame($first['user']->id, $second['user']->id);
        $this->assertAccountChainIsComplete($first['user']->id, $registrationKey);
    }

    /** @throws Exception */
    public function testBannedAccountCannotBeReRegisteredThroughItsKey(): void
    {
        $registrationKey = 'social:9002' . random_int(100000, 999999);
        $service = $this->service();
        $created = $service->findOrCreateUserBySocialIdentity($registrationKey);
        $this->users->newQuery()->whereKey($created['user']->id)->update(['status' => 0]);

        try {
            $service->findOrCreateUserBySocialIdentity($registrationKey);
            $this->fail('Expected the banned account to block re-registration');
        } catch (RuntimeException $e) {
            $this->assertSame(ErrorCode::USER_BANNED->value, $e->getCode());
        }

        $this->assertSame(1, $this->userCount());
    }

    /** @throws Exception */
    public function testSoftDeletedAccountCannotBeReRegisteredThroughItsKey(): void
    {
        $registrationKey = 'social:9003' . random_int(100000, 999999);
        $service = $this->service();
        $created = $service->findOrCreateUserBySocialIdentity($registrationKey);
        $this->users->newQuery()
            ->whereKey($created['user']->id)
            ->update(['deleted_at' => gmdate('Y-m-d H:i:s.v')]);

        try {
            $service->findOrCreateUserBySocialIdentity($registrationKey);
            $this->fail('Expected the soft-deleted account to block re-registration');
        } catch (RuntimeException $e) {
            $this->assertSame(ErrorCode::USER_NOT_FOUND->value, $e->getCode());
        }

        $this->assertSame(1, $this->userCount());
        $this->assertSame(
            2,
            $this->personas->newQuery()->count(),
            'A rejected re-registration must not leave a persona behind',
        );
    }

    /** @throws JsonException */
    private function assertAccountChainIsComplete(string $userId, string $registrationKey): void
    {
        $users = $this->users->newQuery()
            ->where('social_registration_key', $registrationKey)
            ->get(['id', 'status', 'phone', 'register_channel', 'social_registration_key', 'last_login_at']);

        $this->assertCount(1, $users, 'One key must own exactly one account');
        $user = $users->first()->getAttributes();
        $this->assertSame($userId, (string)$user['id']);
        $this->assertSame(1, (int)$user['status']);
        $this->assertNull($user['phone']);
        $this->assertSame('wechat', (string)$user['register_channel']);
        $this->assertNotNull($user['last_login_at']);

        $personas = $this->personas->newQuery()
            ->where('user_id', $userId)
            ->get(['id', 'user_id', 'nickname', 'type', 'is_internal_alias']);
        $this->assertCount(2, $personas, 'Registration must create one main persona and one internal alias');
        $mainPersonas = $personas->where('type', 1);
        $aliases = $personas->where('type', 2)->where('is_internal_alias', true);
        $this->assertCount(1, $mainPersonas);
        $this->assertCount(1, $aliases);
        $persona = $mainPersonas->first()->getAttributes();
        $this->assertSame($userId, (string)$persona['user_id']);

        $events = $this->outbox->newQuery()
            ->orderBy('aggregate_type')
            ->get(['aggregate_type', 'aggregate_id', 'event_key', 'trace_id', 'event_type', 'payload'])
            ->toArray();
        $this->assertCount(3, $events, 'Registration must publish the user, main persona and alias events');

        $eventsByType = array_column($events, null, 'event_type');
        $userEvent = $eventsByType[EventTypes::USER_REGISTERED];
        $personaEvents = array_values(array_filter($events, static fn (array $event): bool =>
            $event['event_type'] === EventTypes::PERSONA_UPDATED));
        $this->assertCount(2, $personaEvents);
        $eventsByPersona = array_column($personaEvents, null, 'aggregate_id');
        $personaEvent = $eventsByPersona[(string)$persona['id']];
        $aliasEvent = $eventsByPersona[(string)$aliases->first()->getAttribute('id')];
        $this->assertSame($userId, (string)$aliasEvent['event_key']);
        $this->assertSame($userEvent['trace_id'], $aliasEvent['trace_id']);
        $this->assertSame('user', $userEvent['aggregate_type']);
        $this->assertSame($userId, (string)$userEvent['aggregate_id']);
        $this->assertSame($userId, (string)$userEvent['event_key']);
        $this->assertNotSame('', (string)$userEvent['trace_id']);
        $userPayload = json_decode((string)$userEvent['payload'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($userId, (string)$userPayload['id']);
        $this->assertSame('persona', $personaEvent['aggregate_type']);
        $this->assertSame((string)$persona['id'], (string)$personaEvent['aggregate_id']);
        $this->assertSame($userId, (string)$personaEvent['event_key']);
        $this->assertSame($userEvent['trace_id'], $personaEvent['trace_id']);
    }

    private function userCount(): int
    {
        return $this->users->newQueryWithoutScopes()->count();
    }

    /**
     * 让所有工作进程使用同一注册键，并返回原始结果。
     *
     * 每个进程连接、报告就绪后，在各自的 INSERT users 内等待；测试同时释放全部进程，使注册键唯一约束串行化竞争。
     *
     * @return list<string>
     */
    private function registerConcurrently(string $registrationKey): array
    {
        $prefix = tempnam(sys_get_temp_dir(), 'social-registration-');
        if ($prefix === false) {
            $this->fail('Unable to create a concurrency coordination path');
        }
        unlink($prefix);

        $startPath = $prefix . '-start';
        $insertReleasePath = $prefix . '-insert-release';
        $readyPaths = [];
        $insertReadyPaths = [];
        $resultPaths = [];
        /** @var list<array{process: resource, pipes: array<int, resource>}> $workers */
        $workers = [];

        try {
            for ($index = 0; $index < self::WORKERS; $index++) {
                $readyPaths[$index] = $prefix . '-' . $index . '-ready';
                $insertReadyPaths[$index] = $prefix . '-' . $index . '-insert-ready';
                $resultPaths[$index] = $prefix . '-' . $index . '-result';
                $pipes = [];
                $process = proc_open(
                    [
                        PHP_BINARY,
                        __DIR__ . '/fixtures/PdoSocialRegistrationWorker.php',
                        $readyPaths[$index],
                        $resultPaths[$index],
                        $startPath,
                        $registrationKey,
                        'wechat',
                        (string)($index + 1),
                        $insertReadyPaths[$index],
                        $insertReleasePath,
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
                    throw new RuntimeException('Unable to start a concurrent registration worker');
                }
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $workers[] = ['process' => $process, 'pipes' => $pipes];
            }

            $this->waitForPath($readyPaths);
            file_put_contents($startPath, 'start');
            $this->waitForPath($insertReadyPaths);
            file_put_contents($insertReleasePath, 'release');

            return $this->collectResults($workers, $resultPaths);
        } finally {
            // 即使屏障失败，也须释放并回收每个工作进程；最后一个写入进程停止后，才能删除共享表。
            file_put_contents($startPath, 'start');
            file_put_contents($insertReleasePath, 'release');
            $this->stopWorkers($workers);

            // 确认没有工作进程能继续写入后，才能删除协调文件。
            foreach (array_merge($readyPaths, $insertReadyPaths, $resultPaths, [$startPath, $insertReleasePath]) as $path) {
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
                throw new RuntimeException('Timed out waiting for a registration worker');
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
                throw new RuntimeException('A registration worker did not finish in time');
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

    private function service(): UserApplicationService
    {
        $snowflake = new Snowflake(1);

        return new UserApplicationService(
            userRepo: new EloquentUserRepository($this->pdo),
            personaRepo: new EloquentPersonaRepository($this->pdo),
            snowflake: $snowflake,
            transactionManager: new EloquentTransactionManager($this->pdo),
            outbox: new OutboxRepository($this->pdo, $snowflake),
            phoneNormalizer: new PhoneNormalizer(),
        );
    }

    private function createSchema(): void
    {
        $schema = EloquentConnection::fromPdo($this->pdo)->getSchemaBuilder();
        foreach (['outbox_events', 'personas', 'users'] as $table) {
            $schema->dropIfExists($table);
        }

        $schema->create('users', static function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->unsignedBigInteger('id')->primary();
            $table->smallInteger('status')->default(1);
            $table->string('phone', 32)->nullable()->unique('uniq_phone');
            $table->string('email', 191)->nullable()->unique('uniq_email');
            $table->string('register_channel', 32)->default('default');
            $table->string('social_registration_key', 128)
                ->charset('utf8mb4')->collation('utf8mb4_bin')->nullable()->unique('uniq_social_registration_key');
            $table->smallInteger('risk_level')->default(0);
            $table->dateTime('last_login_at', 3)->nullable();
            $table->dateTime('last_active_at', 3)->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();
            $table->index('status', 'idx_status');
            $table->index('created_at', 'idx_created_at');
        });
        $schema->create('personas', static function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->smallInteger('type')->default(1);
            $table->boolean('is_internal_alias')->default(false);
            $table->string('nickname', 64);
            $table->string('avatar_key', 255)->nullable();
            $table->string('bio', 500)->nullable();
            $table->smallInteger('status')->default(1);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();
            $table->index(['user_id', 'status'], 'idx_user_status');
            $table->index('created_at', 'idx_created_at');
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
            ],
        );
    }
}
