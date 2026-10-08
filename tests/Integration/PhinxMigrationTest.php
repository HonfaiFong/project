<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
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
                $this->pdo->exec('DROP DATABASE IF EXISTS ' . $this->identifier($this->prefix . $database));
            } catch (Throwable) {
                // Keep the original assertion failure when cleanup itself is unavailable.
            }
        }
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
        $this->assertSame(
            1,
            (int)$this->pdo->query(
                'SELECT COUNT(*) FROM ' . $this->identifier($this->prefix . 'auth_db') . '.`social_identities`',
            )->fetchColumn(),
        );
        $this->assertSame(
            'Abc',
            (string)$this->pdo->query(
                'SELECT `openid` FROM ' . $this->identifier($this->prefix . 'auth_db')
                . '.`social_identities` WHERE `id` = 100000000000000001',
            )->fetchColumn(),
        );
    }

    public function testPartialLegacySchemaIsRepairedAndMigrationCanBeRetried(): void
    {
        $this->executePrefixedBaseline();

        $auth = $this->identifier($this->prefix . 'auth_db');
        $this->pdo->exec(
            'ALTER TABLE ' . $auth . '.`outbox_events` '
            . 'ADD COLUMN `claimed_at` DATETIME(3) NULL AFTER `available_at`, '
            . 'ADD INDEX `idx_aggregate_order` (`aggregate_type`, `aggregate_id`, `id`, `status`), '
            . 'ADD COLUMN `event_key` VARCHAR(64) NOT NULL DEFAULT \'\' AFTER `aggregate_id`, '
            . 'ADD COLUMN `trace_id` VARCHAR(64) NOT NULL DEFAULT \'\' AFTER `event_key`, '
            . 'ADD INDEX `idx_event_key_id_status` (`event_key`, `id`, `status`)',
        );
        $user = $this->identifier($this->prefix . 'user_db');
        $this->pdo->exec(
            'ALTER TABLE ' . $user . '.`outbox_events` '
            . 'ADD COLUMN `event_key` VARCHAR(64) NOT NULL DEFAULT \'\' AFTER `aggregate_id`, '
            . 'ADD COLUMN `trace_id` VARCHAR(64) NOT NULL DEFAULT \'\' AFTER `event_key`',
        );
        $this->insertOutboxEvent(
            $user,
            '00000000-0000-0000-0000-000000000001',
            'old-persona-aggregate',
            'persona.updated.v1',
            'preserved-event-key',
            'existing-trace',
        );
        $this->insertOutboxEvent(
            $user,
            '00000000-0000-0000-0000-000000000002',
            'old-persona-aggregate-2',
            'persona.updated.v1',
            '',
            '',
        );

        $this->assertMigrationSucceeds();
        $this->assertCompleteSchema();

        $statement = $this->pdo->prepare(
            'SELECT `event_id`, `event_key` FROM ' . $user . '.`outbox_events` '
            . 'WHERE `event_id` IN (:first, :second) ORDER BY `event_id`',
        );
        $statement->execute([
            'first' => '00000000-0000-0000-0000-000000000001',
            'second' => '00000000-0000-0000-0000-000000000002',
        ]);
        $this->assertSame(
            [
                ['event_id' => '00000000-0000-0000-0000-000000000001', 'event_key' => 'preserved-event-key'],
                ['event_id' => '00000000-0000-0000-0000-000000000002', 'event_key' => '1001'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );

        $this->assertMigrationSucceeds();
        $this->assertSame(
            6,
            (int)$this->pdo->query(
                'SELECT COUNT(*) FROM ' . $this->identifier($this->prefix . 'meta') . '.`schema_migrations`',
            )->fetchColumn(),
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

        $history = $this->pdo->query(
            'SELECT `name` FROM ' . $this->identifier($this->prefix . 'meta')
            . '.`schema_migrations` ORDER BY `name`',
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(
            array_map(static fn(string $name): string => $name, self::LEGACY_MIGRATIONS),
            $history,
        );
    }

    public function testIdentityIdentifiersUseBinaryCollationAndDistinguishCase(): void
    {
        $this->assertMigrationSucceeds();

        $columns = ['provider_app_id', 'identity_namespace', 'subject_id', 'openid', 'unionid'];
        $statement = $this->pdo->prepare(
            'SELECT `column_name` AS `column_name`, `collation_name` AS `collation_name` '
            . 'FROM `information_schema`.`columns` '
            . "WHERE `table_schema` = :schema AND `table_name` = 'social_identities' "
            . "AND `column_name` IN ('provider_app_id', 'identity_namespace', 'subject_id', 'openid', 'unionid')",
        );
        $statement->execute(['schema' => $this->prefix . 'auth_db']);
        $collations = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $collations[(string)$column['column_name']] = (string)$column['collation_name'];
        }
        foreach ($columns as $column) {
            $this->assertSame('utf8mb4_bin', $collations[$column] ?? null, $column . ' collation');
        }

        $this->insertIdentity('100000000000000001', 'Abc');
        $this->insertIdentity('100000000000000002', 'abc');
        $this->assertSame(
            2,
            (int)$this->pdo->query(
                'SELECT COUNT(*) FROM ' . $this->identifier($this->prefix . 'auth_db') . '.`social_identities`',
            )->fetchColumn(),
        );
    }

    public function testMigrationProcessHonoursPrefixAdvisoryLock(): void
    {
        $lockName = 'social_migrations_' . substr(hash('sha256', $this->prefix), 0, 40);
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
        $status = ['running' => true, 'exitcode' => -1];
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
        $this->pdo->exec($this->prefixDatabaseNames($sql));
    }

    private function executePrefixedLegacyMigration(string $migration): void
    {
        $path = dirname(__DIR__, 2) . '/infra/docker/mysql/migrations/' . $migration;
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Unable to read legacy migration: ' . $migration);
        }

        $this->pdo->exec($this->prefixDatabaseNames($sql));
    }

    private function createLegacyHistory(): void
    {
        $meta = $this->identifier($this->prefix . 'meta');
        $this->pdo->exec(
            'CREATE DATABASE IF NOT EXISTS ' . $meta . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . $meta . '.`schema_migrations` ('
            . '`name` VARCHAR(255) NOT NULL PRIMARY KEY, '
            . '`applied_at` DATETIME(3) NOT NULL'
            . ') ENGINE=InnoDB',
        );
        $statement = $this->pdo->prepare(
            'INSERT INTO ' . $meta . '.`schema_migrations` (`name`, `applied_at`) '
            . 'VALUES (:name, CURRENT_TIMESTAMP(3))',
        );
        foreach (self::LEGACY_MIGRATIONS as $migration) {
            $statement->execute(['name' => $migration]);
        }
    }

    /** @return array{phinx: int, legacy: int, identities: int} */
    private function migrationCounts(): array
    {
        return [
            'phinx' => (int)$this->pdo->query(
                'SELECT COUNT(*) FROM ' . $this->identifier($this->prefix . 'meta') . '.`phinxlog`',
            )->fetchColumn(),
            'legacy' => (int)$this->pdo->query(
                'SELECT COUNT(*) FROM ' . $this->identifier($this->prefix . 'meta') . '.`schema_migrations`',
            )->fetchColumn(),
            'identities' => (int)$this->pdo->query(
                'SELECT COUNT(*) FROM ' . $this->identifier($this->prefix . 'auth_db') . '.`social_identities`',
            )->fetchColumn(),
        ];
    }

    private function assertCompleteSchema(): void
    {
        foreach (self::LOGICAL_DATABASES as $database) {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM `information_schema`.`schemata` WHERE `schema_name` = :schema',
            );
            $statement->execute(['schema' => $this->prefix . $database]);
            $this->assertSame(1, (int)$statement->fetchColumn(), $database . ' schema');
        }

        $expectedTables = [
            'auth_db' => ['devices', 'refresh_tokens', 'outbox_events', 'social_identities'],
            'user_db' => ['users', 'personas', 'outbox_events'],
            'content_db' => ['posts', 'post_media', 'comments', 'outbox_events'],
            'social_db' => ['likes', 'follows', 'blocks', 'outbox_events'],
            'chat_db' => ['conversations', 'conversation_members', 'outbox_events'],
            'message_db' => ['messages', 'outbox_events'],
        ];
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM `information_schema`.`tables` '
            . 'WHERE `table_schema` = :schema AND `table_name` = :table',
        );
        foreach ($expectedTables as $database => $tables) {
            foreach ($tables as $table) {
                $statement->execute(['schema' => $this->prefix . $database, 'table' => $table]);
                $this->assertSame(1, (int)$statement->fetchColumn(), $database . '.' . $table);
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
        $this->assertIndexMissing('auth_db', 'devices', 'uniq_device_uuid');
    }

    private function assertPhinxHistory(): void
    {
        $versions = $this->pdo->query(
            'SELECT `version` FROM ' . $this->identifier($this->prefix . 'meta')
            . '.`phinxlog` ORDER BY `version`',
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(
            ['20261003000000', '20261003000100'],
            array_map(static fn(mixed $version): string => (string)$version, $versions),
        );

        $legacyNames = $this->pdo->query(
            'SELECT `name` FROM ' . $this->identifier($this->prefix . 'meta')
            . '.`schema_migrations` ORDER BY `name`',
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(self::LEGACY_MIGRATIONS, $legacyNames);
    }

    private function assertColumnExists(string $database, string $table, string $column): void
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM `information_schema`.`columns` '
            . 'WHERE `table_schema` = :schema AND `table_name` = :table AND `column_name` = :column',
        );
        $statement->execute([
            'schema' => $this->prefix . $database,
            'table' => $table,
            'column' => $column,
        ]);
        $this->assertSame(1, (int)$statement->fetchColumn(), $database . '.' . $table . '.' . $column);
    }

    private function assertIndexExists(string $database, string $table, string $index): void
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT `index_name`) FROM `information_schema`.`statistics` '
            . 'WHERE `table_schema` = :schema AND `table_name` = :table AND `index_name` = :index',
        );
        $statement->execute([
            'schema' => $this->prefix . $database,
            'table' => $table,
            'index' => $index,
        ]);
        $this->assertSame(1, (int)$statement->fetchColumn(), $database . '.' . $table . '.' . $index);
    }

    private function assertIndexMissing(string $database, string $table, string $index): void
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT `index_name`) FROM `information_schema`.`statistics` '
            . 'WHERE `table_schema` = :schema AND `table_name` = :table AND `index_name` = :index',
        );
        $statement->execute([
            'schema' => $this->prefix . $database,
            'table' => $table,
            'index' => $index,
        ]);
        $this->assertSame(0, (int)$statement->fetchColumn(), $database . '.' . $table . '.' . $index);
    }

    private function insertIdentity(string $id, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ' . $this->identifier($this->prefix . 'auth_db') . '.`social_identities` '
            . '(`id`, `user_id`, `provider`, `provider_app_id`, `identity_namespace`, `subject_type`, '
            . '`subject_id`, `openid`, `unionid`, `status`, `bound_at`, `last_login_at`, `created_at`, `updated_at`) '
            . "VALUES (:id, NULL, 'wechat', 'wechat-main-app', 'wechat-main', 'openid', "
            . ':subject_id, :openid, NULL, 0, NULL, NULL, :created_at, :updated_at)',
        );
        $now = gmdate('Y-m-d H:i:s.v');
        $statement->execute([
            'id' => $id,
            'subject_id' => $value,
            'openid' => $value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertOutboxEvent(
        string $schema,
        string $eventId,
        string $aggregateId,
        string $eventType,
        string $eventKey,
        string $traceId,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO ' . $schema . '.`outbox_events` '
            . '(`id`, `event_id`, `aggregate_type`, `aggregate_id`, `event_key`, `trace_id`, `event_type`, '
            . '`event_version`, `payload`, `status`, `available_at`, `published_at`, `created_at`) '
            . 'VALUES (:id, :event_id, \'persona\', :aggregate_id, :event_key, :trace_id, :event_type, '
            . '1, :payload, 0, :available_at, NULL, :created_at)',
        );
        $now = gmdate('Y-m-d H:i:s.v');
        $statement->execute([
            'id' => $eventId === '00000000-0000-0000-0000-000000000001' ? '9001' : '9002',
            'event_id' => $eventId,
            'aggregate_id' => $aggregateId,
            'event_key' => $eventKey,
            'trace_id' => $traceId,
            'event_type' => $eventType,
            'payload' => json_encode(['user_id' => '1001'], JSON_THROW_ON_ERROR),
            'available_at' => $now,
            'created_at' => $now,
        ]);
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
