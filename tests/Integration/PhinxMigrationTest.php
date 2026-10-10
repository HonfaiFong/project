<?php

declare(strict_types=1);

namespace Tests\Integration;

use AddSocialIdentityAliases;
use AddUserSocialRegistrationKey;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use JsonException;
use Packages\Database\EloquentConnection;
use Packages\Outbox\Model\OutboxEvent;
use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\IrreversibleMigrationException;
use Phinx\Migration\AbstractMigration;
use Services\AuthService\Infrastructure\Model\SocialIdentity;
use Services\AuthService\Infrastructure\Model\SocialIdentityAlias;
use Services\UserService\Infrastructure\Model\UserModel;
use PDO;
use PDOException;
use Random\RandomException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class PhinxMigrationTest extends TestCase
{
    private const LOGICAL_DATABASES = [
        'auth_db',
        'user_db',
        'content_db',
        'social_db',
        'chat_db',
        'message_db',
        'meta',
    ];

    private const OUTBOX_DATABASES = [
        'auth_db',
        'user_db',
        'content_db',
        'social_db',
        'chat_db',
        'message_db',
    ];

    private const LEGACY_MIGRATIONS = [
        '0001_outbox_claimed_at.sql',
        '0002_outbox_aggregate_order.sql',
        '0003_outbox_event_metadata.sql',
        '0004_outbox_event_key_index.sql',
        '0005_social_identities.sql',
        '0006_device_user_scope.sql',
    ];

    private PDO $pdo;

    private string $prefix;

    /** @throws RandomException */
    protected function setUp(): void
    {
        $database = getenv('SOCIAL_TEST_MYSQL_DATABASE');
        if ($database === false || $database === '') {
            $this->markTestSkipped('SOCIAL_TEST_MYSQL_DATABASE is not configured');
        }
        if (!str_starts_with($database, 'social_test_')) {
            throw new RuntimeException('SOCIAL_TEST_MYSQL_DATABASE must use the social_test_ prefix');
        }

        $this->prefix = $database . '_' . bin2hex(random_bytes(6)) . '_';
        $this->assertPrefixFitsMySqlIdentifiers($this->prefix);

        try {
            $this->pdo = $this->connectServer();
        } catch (Throwable $e) {
            $this->markTestSkipped('MySQL integration service is not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->prefix)) {
            return;
        }

        foreach (self::LOGICAL_DATABASES as $database) {
            try {
                $this->schema()->dropDatabaseIfExists($this->prefix . $database);
            } catch (Throwable) {
                // 清理本身不可用时，保留原始断言失败。
            }
        }
        EloquentConnection::forget($this->pdo);
    }

    public function testFreshMigrationIsIdempotentAndPreservesRowsAndHistory(): void
    {
        $this->assertMigrationSucceeds();
        $this->assertCompleteSchema();
        $this->assertPhinxHistory();

        $this->insertIdentity('100000000000000001', 'Abc');
        $firstCounts = $this->migrationCounts();
        $this->assertMigrationSucceeds();

        $this->assertPhinxHistory();
        $this->assertSame($firstCounts, $this->migrationCounts());
        $this->assertSame(1, $this->model(SocialIdentity::class, 'auth_db')->newQuery()->count());
        $this->assertSame('Abc', $this->model(SocialIdentity::class, 'auth_db')->newQuery()
            ->whereKey('100000000000000001')->value('openid'));
    }

    public function testPersonaMainUniquenessIncludesSoftDeletedRows(): void
    {
        $this->assertMigrationSucceeds();
        $this->assertColumnExists('user_db', 'personas', 'main_user_id');
        $this->assertIndexExists('user_db', 'personas', 'uniq_main_persona_user');
        $this->assertIndexExists('user_db', 'personas', 'idx_user_type_status');

        $table = '`' . $this->prefix . 'user_db`.`personas`';
        $now = gmdate('Y-m-d H:i:s.v');
        $insert = $this->pdo->prepare(
            'INSERT INTO ' . $table . ' '
            . '(`id`, `user_id`, `type`, `nickname`, `avatar_key`, `bio`, `status`, `created_at`, `updated_at`, `deleted_at`) '
            . 'VALUES (:id, :user_id, :type, :nickname, NULL, NULL, 1, :created_at, :updated_at, :deleted_at)',
        );
        $insert->execute([
            'id' => '700000000000000001', 'user_id' => '800000000000000001', 'type' => 1,
            'nickname' => 'Deleted main', 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => $now,
        ]);

        try {
            $insert->execute([
                'id' => '700000000000000002', 'user_id' => '800000000000000001', 'type' => 1,
                'nickname' => 'Replacement main', 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
            ]);
            $this->fail('A soft-deleted main persona must keep the account uniqueness slot');
        } catch (PDOException $exception) {
            $this->assertSame('23000', $exception->getCode());
            $this->assertStringContainsString('uniq_main_persona_user', $exception->getMessage());
        }

        $insert->execute([
            'id' => '700000000000000003', 'user_id' => '800000000000000001', 'type' => 2,
            'nickname' => 'Alias', 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
        ]);
        $this->assertNull($this->pdo->query(
            'SELECT `main_user_id` FROM ' . $table . ' WHERE `id` = 700000000000000003',
        )->fetchColumn());
    }

    public function testPersonaConstraintMigrationRejectsDriftedGeneratedColumn(): void
    {
        $this->assertMigrationSucceeds();
        $table = '`' . $this->prefix . 'user_db`.`personas`';
        $this->pdo->exec(
            'ALTER TABLE ' . $table
            . ' DROP INDEX `uniq_main_persona_user`, DROP COLUMN `main_user_id`, '
            . 'ADD COLUMN `main_user_id` BIGINT UNSIGNED GENERATED ALWAYS AS '
            . '(CASE WHEN `type` = 2 THEN `user_id` ELSE NULL END) STORED, '
            . 'ADD UNIQUE KEY `uniq_main_persona_user` (`main_user_id`)',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('main_user_id exists but is not the expected generated column');
        call_user_func([$this->personaConstraintMigration(), 'up']);
    }

    public function testPersonaConstraintMigrationRejectsDriftedNamedIndex(): void
    {
        $this->assertMigrationSucceeds();
        $table = '`' . $this->prefix . 'user_db`.`personas`';
        $this->pdo->exec(
            'ALTER TABLE ' . $table
            . ' DROP INDEX `idx_user_type_status`, ADD INDEX `idx_user_type_status` (`user_id`)',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('idx_user_type_status has an unexpected definition');
        call_user_func([$this->personaConstraintMigration(), 'up']);
    }

    /** @throws JsonException */
    public function testPartialLegacySchemaIsRepairedAndMigrationCanBeRetried(): void
    {
        $this->executePrefixedBaseline();

        $auth = $this->prefix . 'auth_db';
        $this->schema()->table($auth . '.outbox_events', static function (Blueprint $table): void {
            $table->dateTime('claimed_at', 3)->nullable()->after('available_at');
            $table->index(['aggregate_type', 'aggregate_id', 'id', 'status'], 'idx_aggregate_order');
            $table->string('event_key', 64)->default('')->after('aggregate_id');
            $table->string('trace_id', 64)->default('')->after('event_key');
            $table->index(['event_key', 'id', 'status'], 'idx_event_key_id_status');
        });
        $user = $this->prefix . 'user_db';
        $this->schema()->table($user . '.outbox_events', static function (Blueprint $table): void {
            $table->string('event_key', 64)->default('')->after('aggregate_id');
            $table->string('trace_id', 64)->default('')->after('event_key');
        });
        $this->insertOutboxEvent(
            $user,
            '00000000-0000-0000-0000-000000000001',
            'old-persona-aggregate',
            'preserved-event-key',
            'existing-trace',
        );
        $this->insertOutboxEvent(
            $user,
            '00000000-0000-0000-0000-000000000002',
            'old-persona-aggregate-2',
            '',
            '',
        );

        $this->assertMigrationSucceeds();
        $this->assertCompleteSchema();

        $query = $this->model(OutboxEvent::class, 'user_db')->newQuery();
        $query->select(['event_id', 'event_key'])->whereIn('event_id', [
            '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002',
        ])->orderBy('event_id');
        $this->assertSame(
            [
                ['event_id' => '00000000-0000-0000-0000-000000000001', 'event_key' => 'preserved-event-key'],
                ['event_id' => '00000000-0000-0000-0000-000000000002', 'event_key' => '1001'],
            ],
            $query->get()->map(static fn (OutboxEvent $event): array => $event->getAttributes())->all(),
        );

        $this->assertMigrationSucceeds();
        $this->assertSame(
            6,
            $this->connection()->table($this->prefix . 'meta.schema_migrations')->count(),
        );
    }

    public function testExistingLegacyHistoryIsAdoptedByPhinx(): void
    {
        $this->executePrefixedBaseline();
        foreach (self::LEGACY_MIGRATIONS as $migration) {
            $this->executePrefixedLegacyMigration($migration);
        }
        $this->createLegacyHistory();

        $this->assertMigrationSucceeds();
        $this->assertCompleteSchema();

        $history = $this->connection()->table($this->prefix . 'meta.schema_migrations')->orderBy('name')->pluck('name')->all();
        $this->assertSame(
            array_map(static fn(string $name): string => $name, self::LEGACY_MIGRATIONS),
            $history,
        );
    }

    public function testIdentityIdentifiersUseBinaryCollationAndDistinguishCase(): void
    {
        $this->assertMigrationSucceeds();

        $columns = ['provider_app_id', 'identity_namespace', 'subject_id', 'openid', 'unionid'];
        $collations = $this->connection()->table('information_schema.columns')
            ->where('TABLE_SCHEMA', $this->prefix . 'auth_db')->where('TABLE_NAME', 'social_identities')
            ->whereIn('COLUMN_NAME', $columns)->pluck('COLLATION_NAME', 'COLUMN_NAME')->all();
        foreach ($columns as $column) {
            $this->assertSame('utf8mb4_bin', $collations[$column] ?? null, $column . ' collation');
        }

        $this->insertIdentity('100000000000000001', 'Abc');
        $this->insertIdentity('100000000000000002', 'abc');
        $this->assertSame(
            2,
            $this->model(SocialIdentity::class, 'auth_db')->newQuery()->count(),
        );
    }

    public function testMigrationProcessHonoursPrefixAdvisoryLock(): void
    {
        $lockName = 'social_migrations_' . substr(hash('sha256', $this->prefix), 0, 40);
        // MySQL 会话咨询锁没有 Eloquent 或 Schema Builder API。
        $lock = $this->pdo->prepare('SELECT GET_LOCK(:name, :timeout)');
        $lock->execute(['name' => $lockName, 'timeout' => 0]);
        $this->assertSame(1, (int)$lock->fetchColumn());

        try {
            [$exitCode, $output] = $this->runPhinx(0);
            $this->assertNotSame(0, $exitCode, $output);
            $this->assertStringContainsString('lock', strtolower($output));
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => $lockName]);
        }

        $this->assertMigrationSucceeds();
    }

    public function testSocialRegistrationKeyIsNullableUniqueAndPreservesExistingRows(): void
    {
        $this->assertMigrationSucceeds();
        $this->assertColumnExists('user_db', 'users', 'social_registration_key');
        $this->assertIndexExists('user_db', 'users', 'uniq_social_registration_key');

        $users = $this->prefix . 'user_db.users';
        $columns = array_column($this->schema()->getColumns($users), null, 'name');
        $column = $columns['social_registration_key'];
        $this->assertTrue($column['nullable']);
        $this->assertSame('utf8mb4_bin', $this->connection()->table('information_schema.columns')
            ->where('TABLE_SCHEMA', $this->prefix . 'user_db')->where('TABLE_NAME', 'users')
            ->where('COLUMN_NAME', 'social_registration_key')->value('COLLATION_NAME'));
        $this->assertSame('varchar(128)', $column['type']);

        // 现有手机账户保留 NULL 注册键，且须正常共存。
        $this->insertUser($users, 5001, '+8613800000001', null);
        $this->insertUser($users, 5002, '+8613800000002', null);
        $this->insertUser($users, 5003, '+8613800000003', 'social:1001');
        $accountsAfterMigration = $this->userRowCount($users);
        $this->assertSame(3, $accountsAfterMigration);

        try {
            $this->insertUser($users, 5004, null, 'social:1001');
            $this->fail('Expected the unique social registration key to reject a duplicate');
        } catch (PDOException $exception) {
            $this->assertSame('23000', $exception->getCode());
            $this->assertSame(1062, (int)($exception->errorInfo[1] ?? 0));
            $this->assertStringContainsString('uniq_social_registration_key', $exception->getMessage());
        }

        $this->assertSame($accountsAfterMigration, $this->userRowCount($users));

        // 重复执行迁移不应产生变更，所有行须保持完整。
        $this->assertMigrationSucceeds();
        $this->assertSame($accountsAfterMigration, $this->userRowCount($users));
        $this->assertSame(
            'social:1001',
            $this->model(UserModel::class, 'user_db')->newQuery()->whereKey('5003')->value('social_registration_key'),
        );
    }

    public function testAliasSchemaPreservesCompositeKeyBinaryCollationAndForeignKey(): void
    {
        $this->assertMigrationSucceeds();
        $table = $this->prefix . 'auth_db.social_identity_aliases';
        $this->assertTrue($this->schema()->hasIndex($table, ['provider', 'provider_app_id', 'openid'], 'primary'));
        $foreignKeys = $this->schema()->getForeignKeys($table);
        $this->assertCount(1, $foreignKeys);
        $this->assertSame(['social_identity_id'], $foreignKeys[0]['columns']);
        $this->assertSame($this->prefix . 'auth_db', $foreignKeys[0]['foreign_schema']);
        $this->assertSame('social_identities', $foreignKeys[0]['foreign_table']);
        $this->assertSame(['id'], $foreignKeys[0]['foreign_columns']);

        $this->insertIdentity('100000000000000001', 'canonical');
        $aliases = $this->model(SocialIdentityAlias::class, 'auth_db');
        foreach (['Abc', 'abc'] as $openId) {
            $aliases->newQuery()->create([
                'provider' => 'wechat', 'provider_app_id' => 'app', 'openid' => $openId,
                'social_identity_id' => '100000000000000001',
            ]);
        }
        $this->assertSame(2, $aliases->newQuery()->count());

        try {
            $aliases->newQuery()->create([
                'provider' => 'wechat', 'provider_app_id' => 'app', 'openid' => 'Abc',
                'social_identity_id' => '100000000000000001',
            ]);
            $this->fail('A duplicate alias tuple must be rejected');
        } catch (PDOException $exception) {
            $this->assertSame(1062, (int)($exception->errorInfo[1] ?? 0));
        }
        $beforeRetry = $this->migrationCounts();
        $this->assertMigrationSucceeds();
        $this->assertSame($beforeRetry, $this->migrationCounts());
        $this->assertSame(2, $aliases->newQuery()->count());

        try {
            $aliases->newQuery()->create([
                'provider' => 'wechat', 'provider_app_id' => 'app', 'openid' => 'missing-owner',
                'social_identity_id' => '999999999999999999',
            ]);
            $this->fail('The alias must reference an existing identity');
        } catch (PDOException $exception) {
            $this->assertSame(1452, (int)($exception->errorInfo[1] ?? 0));
        }
    }

    public function testRegistrationKeyMigrationPreservesLegacyRowsAndRepairsAMissingIndex(): void
    {
        $this->executePrefixedBaseline();
        $users = $this->model(UserModel::class, 'user_db');
        $id = '9223372036854775808';
        $users->newQuery()->create([
            'id' => $id, 'status' => 1, 'phone' => '+8613800000000', 'email' => null,
            'register_channel' => 'legacy', 'risk_level' => 2,
            'created_at' => '2020-01-01 00:00:00.123', 'updated_at' => '2020-01-02 00:00:00.456',
        ]);
        $before = $users->newQuery()->findOrFail($id)->getAttributes();
        $this->assertMigrationSucceeds();
        $after = $users->newQuery()->findOrFail($id)->getAttributes();
        $this->assertNull($after['social_registration_key']);
        unset($after['social_registration_key']);
        $this->assertSame($before, $after);

        require_once dirname(__DIR__, 2) . '/database/migrations/20261009000000_add_user_social_registration_key.php';
        $migration = new AddUserSocialRegistrationKey('test', 20261009000000);
        $migration->setAdapter(new MysqlAdapter([
            'connection' => $this->pdo, 'name' => $this->prefix . 'meta',
            'migration_table' => $this->prefix . 'meta.phinxlog', 'schema_prefix' => $this->prefix,
        ]));
        $table = $this->prefix . 'user_db.users';
        $this->schema()->table($table, static function (Blueprint $table): void {
            $table->dropUnique('uniq_social_registration_key');
        });
        $this->assertFalse($this->schema()->hasIndex($table, 'uniq_social_registration_key'));
        $migration->up();
        $this->assertTrue($this->schema()->hasIndex($table, 'uniq_social_registration_key', 'unique'));
        $complete = $users->newQuery()->findOrFail($id)->getAttributes();
        $migration->up();
        $this->assertSame($complete, $users->newQuery()->findOrFail($id)->getAttributes());
    }

    /** @throws IrreversibleMigrationException */
    public function testSocialMigrationsRefuseDestructiveRollback(): void
    {
        $this->assertMigrationSucceeds();
        $this->insertIdentity('100000000000000001', 'preserved');
        $before = $this->migrationCounts();
        require_once dirname(__DIR__, 2) . '/database/migrations/20261009000000_add_user_social_registration_key.php';
        require_once dirname(__DIR__, 2) . '/database/migrations/20261009000100_add_social_identity_aliases.php';
        foreach ([
            new AddUserSocialRegistrationKey('test', 20261009000000),
            new AddSocialIdentityAliases('test', 20261009000100),
        ] as $migration) {
            try {
                $migration->down();
                $this->fail('Social persistence migrations must reject destructive rollback');
            } catch (IrreversibleMigrationException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
        $this->assertSame($before, $this->migrationCounts());
        $this->assertColumnExists('user_db', 'users', 'social_registration_key');
        $this->assertTrue($this->schema()->hasTable($this->prefix . 'auth_db.social_identity_aliases'));
    }

    private function personaConstraintMigration(): object
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261010000000_add_persona_identity_constraints.php';
        /** @var class-string<AbstractMigration> $migrationClass */
        $migrationClass = 'AddPersonaIdentityConstraints';
        $migration = new $migrationClass('test', 20261010000000);
        $migration->setAdapter(new MysqlAdapter([
            'connection' => $this->pdo, 'name' => $this->prefix . 'meta',
            'migration_table' => $this->prefix . 'meta.phinxlog', 'schema_prefix' => $this->prefix,
        ]));

        return $migration;
    }

    private function assertMigrationSucceeds(): void
    {
        [$exitCode, $output] = $this->runPhinx();
        $this->assertSame(0, $exitCode, $output);
    }

    /** @return array{0: int, 1: string} */
    private function runPhinx(int $lockTimeout = 30): array
    {
        $environment = getenv();
        $environment = array_merge($environment, [
            'MYSQL_HOST' => getenv('SOCIAL_TEST_MYSQL_HOST') ?: '127.0.0.1',
            'MYSQL_PORT' => getenv('SOCIAL_TEST_MYSQL_PORT') ?: '3306',
            'MYSQL_USER' => getenv('SOCIAL_TEST_MYSQL_USER') ?: 'root',
            'MYSQL_PASSWORD' => getenv('SOCIAL_TEST_MYSQL_PASSWORD') ?: '',
            'MYSQL_SCHEMA_PREFIX' => $this->prefix,
            'MYSQL_MIGRATION_LOCK_TIMEOUT' => (string)$lockTimeout,
        ]);

        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/vendor/bin/phinx',
                'migrate',
                '--configuration=' . dirname(__DIR__, 2) . '/phinx.php',
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
            $environment,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start Phinx process');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + 30.0;
        do {
            $status = proc_get_status($process);
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false) {
                $stdout .= $chunk;
            }
            $chunk = stream_get_contents($pipes[2]);
            if ($chunk !== false) {
                $stderr .= $chunk;
            }
            if (!$status['running']) {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        if ($status['running'] === true) {
            proc_terminate($process, 9);
        }

        $chunk = stream_get_contents($pipes[1]);
        if ($chunk !== false) {
            $stdout .= $chunk;
        }
        $chunk = stream_get_contents($pipes[2]);
        if ($chunk !== false) {
            $stderr .= $chunk;
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($process);
        $exitCode = $status['exitcode'] === -1 ? $closeCode : $status['exitcode'];
        $output = trim($stdout . "\n" . $stderr);
        if (strlen($output) > 24_000) {
            $output = substr($output, 0, 4_000)
                . "\n... Phinx output truncated by integration test ...\n"
                . substr($output, -20_000);
        }

        return [$exitCode, $output];
    }

    private function connectServer(): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;charset=utf8mb4',
                getenv('SOCIAL_TEST_MYSQL_HOST') ?: '127.0.0.1',
                (int)(getenv('SOCIAL_TEST_MYSQL_PORT') ?: 3306),
            ),
            getenv('SOCIAL_TEST_MYSQL_USER') ?: 'root',
            getenv('SOCIAL_TEST_MYSQL_PASSWORD') ?: '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_TIMEOUT => 5,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ],
        );
    }

    private function executePrefixedBaseline(): void
    {
        $path = dirname(__DIR__, 2) . '/infra/docker/mysql/init/01-databases.sql';
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Unable to read baseline SQL');
        }

        $sql = preg_replace('/^GRANT ALL PRIVILEGES.*\R/m', '', $sql);
        $sql = preg_replace('/^FLUSH PRIVILEGES;\s*\R?/m', '', (string)$sql);
        if ($sql === null) {
            throw new RuntimeException('Unable to prepare baseline SQL');
        }
        // 执行实际版本化 SQL 测试文件；重新编写会使测试不再覆盖历史迁移纳入流程。
        $this->pdo->exec($this->prefixDatabaseNames($sql));
    }

    private function executePrefixedLegacyMigration(string $migration): void
    {
        $path = dirname(__DIR__, 2) . '/infra/docker/mysql/migrations/' . $migration;
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Unable to read legacy migration: ' . $migration);
        }

        // 执行实际版本化 SQL 测试文件；重新编写会使测试不再覆盖历史迁移纳入流程。
        $this->pdo->exec($this->prefixDatabaseNames($sql));
    }

    private function createLegacyHistory(): void
    {
        $meta = $this->prefix . 'meta';
        $schemas = array_column($this->schema()->getSchemas(), 'name');
        if (!in_array($meta, $schemas, true)) {
            $this->schema()->createDatabase($meta);
        }
        $history = $meta . '.schema_migrations';
        if (!$this->schema()->hasTable($history)) {
            $this->schema()->create($history, static function (Blueprint $table): void {
                $table->charset('utf8mb4');
                $table->collation('utf8mb4_unicode_ci');
                $table->string('name')->primary();
                $table->dateTime('applied_at', 3);
            });
        }
        $this->connection()->table($history)->insert(array_map(static fn (string $migration): array => [
            'name' => $migration, 'applied_at' => gmdate('Y-m-d H:i:s.v'),
        ], self::LEGACY_MIGRATIONS));
    }

    /** @return array{phinx: int, legacy: int, identities: int} */
    private function migrationCounts(): array
    {
        return [
            'phinx' => $this->connection()->table($this->prefix . 'meta.phinxlog')->count(),
            'legacy' => $this->connection()->table($this->prefix . 'meta.schema_migrations')->count(),
            'identities' => $this->model(SocialIdentity::class, 'auth_db')->newQuery()->count(),
        ];
    }

    private function assertCompleteSchema(): void
    {
        $schemas = array_column($this->schema()->getSchemas(), 'name');
        foreach (self::LOGICAL_DATABASES as $database) {
            $this->assertContains($this->prefix . $database, $schemas, $database . ' schema');
        }

        $expectedTables = [
            'auth_db' => ['devices', 'refresh_tokens', 'outbox_events', 'social_identities', 'social_identity_aliases'],
            'user_db' => ['users', 'personas', 'outbox_events'],
            'content_db' => ['posts', 'post_media', 'comments', 'outbox_events'],
            'social_db' => ['likes', 'follows', 'blocks', 'outbox_events'],
            'chat_db' => ['conversations', 'conversation_members', 'outbox_events'],
            'message_db' => ['messages', 'outbox_events'],
        ];
        foreach ($expectedTables as $database => $tables) {
            foreach ($tables as $table) {
                $this->assertTrue($this->schema()->hasTable($this->prefix . $database . '.' . $table), $database . '.' . $table);
            }
        }

        foreach (self::OUTBOX_DATABASES as $database) {
            $this->assertColumnExists($database, 'outbox_events', 'claimed_at');
            $this->assertColumnExists($database, 'outbox_events', 'event_key');
            $this->assertColumnExists($database, 'outbox_events', 'trace_id');
            $this->assertIndexExists($database, 'outbox_events', 'idx_aggregate_order');
            $this->assertIndexExists($database, 'outbox_events', 'idx_event_key_id_status');
        }
        $this->assertIndexExists('auth_db', 'devices', 'uniq_user_device_uuid');
        $this->assertFalse($this->schema()->hasIndex($this->prefix . 'auth_db.devices', 'uniq_device_uuid'));
        $this->assertColumnExists('user_db', 'users', 'social_registration_key');
        $this->assertIndexExists('user_db', 'users', 'uniq_social_registration_key');
    }

    private function assertPhinxHistory(): void
    {
        $versions = $this->connection()->table($this->prefix . 'meta.phinxlog')->orderBy('version')->pluck('version')->all();
        $this->assertSame($this->migrationVersions(), array_map(static fn (mixed $version): string => (string)$version, $versions));

        $legacyNames = $this->connection()->table($this->prefix . 'meta.schema_migrations')->orderBy('name')->pluck('name')->all();
        $this->assertSame(self::LEGACY_MIGRATIONS, $legacyNames);
    }

    /**
     * 已执行历史须与每个迁移文件一一对应；读取目录可在并行开发新增迁移时保持断言正确，无须固定手工维护的版本列表。
     *
     * @return list<string>
     */
    private function migrationVersions(): array
    {
        $versions = [];
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.php') ?: [] as $path) {
            if (preg_match('/\A(\d{14})_/', basename($path), $matches) === 1) {
                $versions[] = $matches[1];
            }
        }
        sort($versions);

        return $versions;
    }

    private function assertColumnExists(string $database, string $table, string $column): void
    {
        $this->assertTrue($this->schema()->hasColumn($this->prefix . $database . '.' . $table, $column), $database . '.' . $table . '.' . $column);
    }

    private function assertIndexExists(string $database, string $table, string $index): void
    {
        $this->assertTrue($this->schema()->hasIndex($this->prefix . $database . '.' . $table, $index), $database . '.' . $table . '.' . $index);
    }

    private function userRowCount(string $table): int
    {
        return EloquentConnection::model(UserModel::class, $this->pdo)->setTable($table)->newQueryWithoutScopes()->count();
    }

    private function insertUser(string $table, int $id, ?string $phone, ?string $socialRegistrationKey): void
    {
        $now = gmdate('Y-m-d H:i:s.v');
        EloquentConnection::model(UserModel::class, $this->pdo)->setTable($table)->newQuery()->create([
            'id' => (string)$id, 'status' => 1, 'phone' => $phone, 'email' => null,
            'register_channel' => 'default', 'risk_level' => 0,
            'social_registration_key' => $socialRegistrationKey,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function insertIdentity(string $id, string $value): void
    {
        $now = gmdate('Y-m-d H:i:s.v');
        $this->model(SocialIdentity::class, 'auth_db')->newQuery()->create([
            'id' => $id, 'user_id' => null, 'provider' => 'wechat', 'provider_app_id' => 'wechat-main-app',
            'identity_namespace' => 'wechat-main', 'subject_type' => 'openid', 'subject_id' => $value,
            'openid' => $value, 'unionid' => null, 'status' => 0, 'bound_at' => null, 'last_login_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** @throws JsonException */
    private function insertOutboxEvent(
        string $schema,
        string $eventId,
        string $aggregateId,
        string $eventKey,
        string $traceId,
    ): void {
        $now = gmdate('Y-m-d H:i:s.v');
        EloquentConnection::model(OutboxEvent::class, $this->pdo)->setTable($schema . '.outbox_events')->newQuery()->create([
            'id' => $eventId === '00000000-0000-0000-0000-000000000001' ? '9001' : '9002',
            'event_id' => $eventId, 'aggregate_type' => 'persona', 'aggregate_id' => $aggregateId,
            'event_key' => $eventKey, 'trace_id' => $traceId, 'event_type' => 'persona.updated.v1', 'event_version' => 1,
            'payload' => json_encode(['user_id' => '1001'], JSON_THROW_ON_ERROR), 'status' => 0,
            'available_at' => $now, 'published_at' => null, 'created_at' => $now,
        ]);
    }

    private function connection(): Connection
    {
        return EloquentConnection::fromPdo($this->pdo);
    }

    private function schema(): Builder
    {
        return $this->connection()->getSchemaBuilder();
    }

    /**
     * @template T of Model
     * @param class-string<T> $class
     * @return T
     */
    private function model(string $class, string $database): Model
    {
        $model = EloquentConnection::model($class, $this->pdo);

        return $model->setTable($this->prefix . $database . '.' . $model->getTable());
    }

    private function prefixDatabaseNames(string $sql): string
    {
        foreach (self::LOGICAL_DATABASES as $database) {
            $sql = str_replace('`' . $database . '`', $this->identifier($this->prefix . $database), $sql);
        }

        return $sql;
    }

    private function assertPrefixFitsMySqlIdentifiers(string $prefix): void
    {
        foreach (self::LOGICAL_DATABASES as $database) {
            if (strlen($prefix . $database) > 64) {
                throw new RuntimeException('Generated database name exceeds MySQL identifier length');
            }
        }
    }

    private function identifier(string $identifier): string
    {
        return '`' . $identifier . '`';
    }
}
