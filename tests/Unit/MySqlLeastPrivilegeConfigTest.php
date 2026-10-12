<?php

declare(strict_types=1);

namespace Tests\Unit;

use RuntimeException;
use Tests\TestCase;

/**
 * Deployment contract for the MySQL account boundary.
 *
 * These checks deliberately inspect the compose and init artifacts instead of
 * connecting as root. The opt-in integration suite exercises the same grants
 * against a real MySQL instance when MYSQL_PRIVILEGE_TEST=1 is configured.
 */
final class MySqlLeastPrivilegeConfigTest extends TestCase
{
    /** @var array<string, array{user: string, password: string, database: string}> */
    private const RUNTIME_ACCOUNTS = [
        'auth-service' => [
            'user' => 'auth_runtime',
            'password' => 'MYSQL_AUTH_RUNTIME_PASSWORD',
            'database' => 'auth_db',
        ],
        'user-service' => [
            'user' => 'user_runtime',
            'password' => 'MYSQL_USER_RUNTIME_PASSWORD',
            'database' => 'user_db',
        ],
        'content-service' => [
            'user' => 'content_runtime',
            'password' => 'MYSQL_CONTENT_RUNTIME_PASSWORD',
            'database' => 'content_db',
        ],
        'social-service' => [
            'user' => 'social_runtime',
            'password' => 'MYSQL_SOCIAL_RUNTIME_PASSWORD',
            'database' => 'social_db',
        ],
        'chat-service' => [
            'user' => 'chat_runtime',
            'password' => 'MYSQL_CHAT_RUNTIME_PASSWORD',
            'database' => 'chat_db',
        ],
        'message-service' => [
            'user' => 'message_runtime',
            'password' => 'MYSQL_MESSAGE_RUNTIME_PASSWORD',
            'database' => 'message_db',
        ],
    ];

    public function testBusinessContainersUseDistinctNonRootAccounts(): void
    {
        $compose = $this->read('compose.yaml');

        self::assertStringNotContainsString('MYSQL_USER: root', $compose);
        self::assertStringNotContainsString('MYSQL_USER=root', $compose);
        self::assertStringNotContainsString('MYSQL_PASSWORD: ${MYSQL_PASSWORD', $compose);
        self::assertStringNotContainsString('MYSQL_PASSWORD=${MYSQL_PASSWORD', $compose);

        $users = [];
        foreach (self::RUNTIME_ACCOUNTS as $service => $account) {
            $section = $this->serviceSection($compose, $service);
            self::assertMatchesRegularExpression(
                '/MYSQL_USER[=:] ?' . preg_quote($account['user'], '/') . '/',
                $section,
                $service . ' must use its own runtime account',
            );
            self::assertStringContainsString(
                $account['password'],
                $section,
                $service . ' must receive only its own password variable',
            );
            self::assertStringNotContainsString('${MYSQL_PASSWORD', $section);
            self::assertUnique($users, $account['user'], 'runtime account names must be unique');
            $users[] = $account['user'];
        }

        $relay = $this->serviceSection($compose, 'user-outbox-relay');
        self::assertStringContainsString('MYSQL_USER: user_outbox_relay', $relay);
        self::assertStringContainsString('MYSQL_USER_OUTBOX_RELAY_PASSWORD', $relay);

        $migration = $this->serviceSection($compose, 'database-migrate');
        self::assertStringContainsString('MYSQL_USER: migration_ddl', $migration);
        self::assertStringContainsString('MYSQL_MIGRATION_PASSWORD', $migration);
    }

    public function testEnvironmentTemplatesDeclareEveryCredentialSeparately(): void
    {
        foreach (['.env.example', '.env.production.example'] as $path) {
            $env = $this->read($path);
            self::assertStringContainsString('MYSQL_PASSWORD=', $env, $path);

            foreach (self::RUNTIME_ACCOUNTS as $account) {
                self::assertMatchesRegularExpression('/^' . $account['password'] . '=/m', $env, $path);
            }
            self::assertMatchesRegularExpression('/^MYSQL_USER_OUTBOX_RELAY_PASSWORD=/m', $env, $path);
            self::assertMatchesRegularExpression('/^MYSQL_MIGRATION_PASSWORD=/m', $env, $path);
        }
    }

    public function testInitSeparatesRuntimeDmlFromMigrationDdl(): void
    {
        $baseline = $this->read('infra/docker/mysql/init/01-databases.sql');
        $accounts = $this->read('infra/docker/mysql/init/02-runtime-accounts.sh');

        self::assertStringNotContainsString("GRANT ALL PRIVILEGES ON *.* TO 'root'@'%'", $baseline);
        self::assertStringContainsString('CREATE USER IF NOT EXISTS', $accounts);
        self::assertStringContainsString('REVOKE ALL PRIVILEGES, GRANT OPTION', $accounts);
        self::assertStringContainsString('GRANT SELECT, INSERT, UPDATE, DELETE ON \\`${database}\\`.* TO \'${user}\'@\'%\';', $accounts);
        foreach (self::RUNTIME_ACCOUNTS as $account) {
            self::assertStringContainsString(
                'create_runtime_user ' . $account['user'] . ' ' . $account['database'],
                $accounts,
            );
        }
        self::assertStringContainsString("GRANT SELECT, UPDATE ON \\`\${database}\\`.\\`outbox_events\\` TO '\${user}'", $accounts);
        self::assertStringContainsString('create_relay_user user_outbox_relay user_db', $accounts);
        self::assertStringContainsString('create_relay_user message_outbox_relay message_db', $accounts);
        self::assertStringContainsString("ON \\`%_db\\`.* TO 'migration_ddl'", $accounts);
        self::assertStringContainsString("ON \\`%meta\\`.* TO 'migration_ddl'", $accounts);
        self::assertStringNotContainsString("'migration_ddl'@'%' WITH GRANT OPTION", $accounts);
    }

    public function testRuntimeBootstrapDefaultsCannotFallBackToRoot(): void
    {
        $files = [
            'services/auth-service/bin/server.php',
            'services/user-service/bin/server.php',
            'services/content-service/bin/server.php',
            'services/social-service/bin/server.php',
            'services/chat-service/bin/server.php',
            'services/message-service/bin/server.php',
            'services/user-service/bin/outbox-relay.php',
            'phinx.php',
        ];

        foreach ($files as $file) {
            self::assertStringNotContainsString("getenv('MYSQL_USER') ?: 'root'", $this->read($file), $file);
        }
    }

    private function serviceSection(string $compose, string $service): string
    {
        $start = strpos($compose, '  ' . $service . ':');
        if ($start === false) {
            throw new RuntimeException('Service not found in compose.yaml: ' . $service);
        }

        $tail = substr($compose, $start + 2);
        if (preg_match('/^  [a-zA-Z0-9_-]+:/m', $tail, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $tail;
        }

        return substr($tail, 0, (int)$match[0][1]);
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read ' . $relativePath);
        }

        return $contents;
    }

    /** @param list<string> $values */
    private static function assertUnique(array $values, string $value, string $message): void
    {
        self::assertFalse(in_array($value, $values, true), $message);
    }
}
