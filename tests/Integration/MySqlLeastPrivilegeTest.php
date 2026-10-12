<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Optional black-box check for the grants in 02-runtime-accounts.sh.
 *
 * Run only against a disposable MySQL instance with
 * MYSQL_PRIVILEGE_TEST=1. The test creates and removes one probe table per
 * logical schema, then proves that runtime users cannot cross schemas or DDL
 * while migration_ddl can create/drop a temporary database and table.
 */
final class MySqlLeastPrivilegeTest extends TestCase
{
    /** @var array<string, array{database: string, password: string}> */
    private const RUNTIME_ACCOUNTS = [
        'auth_runtime' => ['database' => 'auth_db', 'password' => 'MYSQL_AUTH_RUNTIME_PASSWORD'],
        'user_runtime' => ['database' => 'user_db', 'password' => 'MYSQL_USER_RUNTIME_PASSWORD'],
        'content_runtime' => ['database' => 'content_db', 'password' => 'MYSQL_CONTENT_RUNTIME_PASSWORD'],
        'social_runtime' => ['database' => 'social_db', 'password' => 'MYSQL_SOCIAL_RUNTIME_PASSWORD'],
        'chat_runtime' => ['database' => 'chat_db', 'password' => 'MYSQL_CHAT_RUNTIME_PASSWORD'],
        'message_runtime' => ['database' => 'message_db', 'password' => 'MYSQL_MESSAGE_RUNTIME_PASSWORD'],
    ];

    private PDO $admin;

    /** @var list<string> */
    private array $probeTables = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('MYSQL_PRIVILEGE_TEST') !== '1') {
            self::markTestSkipped('Set MYSQL_PRIVILEGE_TEST=1 for the disposable MySQL privilege test.');
        }

        $this->requireEnvironment('MYSQL_PASSWORD');
        foreach (self::RUNTIME_ACCOUNTS as $account) {
            $this->requireEnvironment($account['password']);
        }
        $this->requireEnvironment('MYSQL_USER_OUTBOX_RELAY_PASSWORD');
        $this->requireEnvironment('MYSQL_MESSAGE_OUTBOX_RELAY_PASSWORD');
        $this->requireEnvironment('MYSQL_MIGRATION_PASSWORD');

        try {
            $this->admin = $this->connect('root', (string)getenv('MYSQL_PASSWORD'));
            foreach (self::RUNTIME_ACCOUNTS as $account) {
                $this->admin->exec(
                    'CREATE TABLE IF NOT EXISTS ' . $this->identifier($account['database'])
                    . '.`__mysql_privilege_probe` (`id` INT NOT NULL PRIMARY KEY)',
                );
                $this->admin->exec(
                    'INSERT IGNORE INTO ' . $this->identifier($account['database'])
                    . '.`__mysql_privilege_probe` (`id`) VALUES (1)',
                );
                $this->probeTables[] = $account['database'];
            }
            $this->admin->exec(
                'CREATE TABLE IF NOT EXISTS `user_db`.`__mysql_privilege_relay_probe` '
                . '(`id` INT NOT NULL PRIMARY KEY)',
            );
        } catch (Throwable $exception) {
            self::markTestSkipped('MySQL privilege test database is not ready: ' . $exception->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            foreach ($this->probeTables as $database) {
                try {
                    $this->admin->exec('DROP TABLE IF EXISTS ' . $this->identifier($database) . '.`__mysql_privilege_probe`');
                } catch (Throwable) {
                    // Preserve the original assertion failure; cleanup is best effort.
                }
            }
            try {
                $this->admin->exec('DROP TABLE IF EXISTS `user_db`.`__mysql_privilege_relay_probe`');
            } catch (Throwable) {
            }
        }

        parent::tearDown();
    }

    public function testRuntimeAccountsAreDmlOnlyAndSchemaScoped(): void
    {
        $databases = array_column(self::RUNTIME_ACCOUNTS, 'database');

        foreach (self::RUNTIME_ACCOUNTS as $user => $account) {
            $runtime = $this->connect($user, (string)getenv($account['password']));
            $this->assertSame(
                '1',
                (string)$runtime->query(
                    'SELECT id FROM ' . $this->identifier($account['database']) . '.`__mysql_privilege_probe` LIMIT 1',
                )->fetchColumn(),
                $user . ' should read its own schema',
            );

            $this->assertDenied(
                $runtime,
                'DROP TABLE ' . $this->identifier($account['database']) . '.`__mysql_privilege_probe`',
                $user . ' must not DROP tables',
            );

            $otherDatabase = $databases[0] === $account['database'] ? $databases[1] : $databases[0];
            $this->assertDenied(
                $runtime,
                'SELECT id FROM ' . $this->identifier($otherDatabase) . '.`__mysql_privilege_probe` LIMIT 1',
                $user . ' must not read another schema',
            );

            $this->assertDenied(
                $runtime,
                'CREATE TABLE ' . $this->identifier($account['database']) . '.`__mysql_privilege_created` (`id` INT)',
                $user . ' must not CREATE tables',
            );
        }
    }

    public function testOutboxRelayIsRestrictedToTheUserOutboxTable(): void
    {
        $relay = $this->connect(
            'user_outbox_relay',
            (string)getenv('MYSQL_USER_OUTBOX_RELAY_PASSWORD'),
        );
        self::assertSame(
            '0',
            (string)$relay->query('SELECT COUNT(*) FROM `user_db`.`outbox_events`')->fetchColumn(),
        );

        $this->assertDenied(
            $relay,
            'SELECT id FROM `user_db`.`users` LIMIT 1',
            'outbox relay must not read users',
        );
        $this->assertDenied(
            $relay,
            'SELECT id FROM `auth_db`.`devices` LIMIT 1',
            'outbox relay must not read another schema',
        );
        $this->assertDenied(
            $relay,
            'DROP TABLE `user_db`.`__mysql_privilege_relay_probe`',
            'outbox relay must not DROP tables',
        );
    }

    public function testMessageRelayCanOnlyReadAndUpdateItsOutbox(): void
    {
        $relay = $this->connect('message_outbox_relay', (string)getenv('MYSQL_MESSAGE_OUTBOX_RELAY_PASSWORD'));
        $relay->query('SELECT id FROM message_db.outbox_events LIMIT 1');
        self::assertSame(0, $relay->exec('UPDATE message_db.outbox_events SET status = 1 WHERE id = 0'));
        $this->assertDenied($relay, 'SELECT message_id FROM message_db.messages LIMIT 1', 'relay must not read messages');
        $this->assertDenied($relay, 'SELECT id FROM chat_db.conversations LIMIT 1', 'relay must not read chat data');
        $this->assertDenied($relay, 'DELETE FROM message_db.outbox_events WHERE id = 0', 'relay must not delete events');
    }

    public function testMigrationAccountCanPerformDdlButCannotDelegatePrivileges(): void
    {
        $migration = $this->connect('migration_ddl', (string)getenv('MYSQL_MIGRATION_PASSWORD'));
        $database = 'mysql_privilege_test_' . bin2hex(random_bytes(4)) . '_db';
        $table = $this->identifier($database) . '.`probe`';

        try {
            $migration->exec('CREATE DATABASE ' . $this->identifier($database));
            $migration->exec('CREATE TABLE ' . $table . ' (`id` INT NOT NULL PRIMARY KEY)');
            $migration->exec('DROP TABLE ' . $table);
            $migration->exec('DROP DATABASE ' . $this->identifier($database));

            $this->assertDenied(
                $migration,
                "CREATE USER 'privilege_probe'@'%' IDENTIFIED BY 'not-used'",
                'migration account must not create users',
            );
        } finally {
            try {
                $migration->exec('DROP DATABASE IF EXISTS ' . $this->identifier($database));
            } catch (Throwable) {
            }
        }
    }

    private function connect(string $user, string $password): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;charset=utf8mb4',
                getenv('MYSQL_HOST') ?: '127.0.0.1',
                (int)(getenv('MYSQL_PORT') ?: 3306),
            ),
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2],
        );
    }

    private function requireEnvironment(string $name): void
    {
        if (getenv($name) === false || getenv($name) === '') {
            self::markTestSkipped($name . ' is required for MYSQL_PRIVILEGE_TEST=1.');
        }
    }

    private function assertDenied(PDO $connection, string $sql, string $message): void
    {
        $denied = false;
        try {
            $connection->exec($sql);
        } catch (PDOException) {
            $denied = true;
        }

        self::assertTrue($denied, $message);
    }

    private function identifier(string $identifier): string
    {
        if (preg_match('/\A[a-zA-Z0-9_]+\z/', $identifier) !== 1) {
            throw new RuntimeException('Unsafe MySQL identifier: ' . $identifier);
        }

        return '`' . $identifier . '`';
    }
}
