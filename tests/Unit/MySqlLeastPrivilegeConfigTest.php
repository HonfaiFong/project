<?php

declare(strict_types=1);

namespace Tests\Unit;

use RuntimeException;
use Tests\TestCase;

/**
 * Deployment contract for PostgreSQL and MongoDB account boundaries.
 *
 * These checks deliberately inspect the compose and init artifacts instead of
 * connecting as superuser.
 */
final class MySqlLeastPrivilegeConfigTest extends TestCase
{
    /** @var array<string, array{user: string, password: string, schema: string}> */
    private const PG_RUNTIME_ACCOUNTS = [
        'auth-service' => [
            'user' => 'auth_runtime',
            'password' => 'PG_AUTH_RUNTIME_PASSWORD',
            'schema' => 'auth_db',
        ],
        'user-service' => [
            'user' => 'user_runtime',
            'password' => 'PG_USER_RUNTIME_PASSWORD',
            'schema' => 'user_db',
        ],
        'content-service' => [
            'user' => 'content_runtime',
            'password' => 'PG_CONTENT_RUNTIME_PASSWORD',
            'schema' => 'content_db',
        ],
        'social-service' => [
            'user' => 'social_runtime',
            'password' => 'PG_SOCIAL_RUNTIME_PASSWORD',
            'schema' => 'social_db',
        ],
        'chat-service' => [
            'user' => 'chat_runtime',
            'password' => 'PG_CHAT_RUNTIME_PASSWORD',
            'schema' => 'chat_db',
        ],
    ];

    public function testBusinessContainersUseDistinctNonRootAccounts(): void
    {
        $compose = $this->read('compose.yaml');

        self::assertStringNotContainsString('PG_USER: postgres', $compose);
        self::assertStringNotContainsString('PG_USER=postgres', $compose);
        self::assertStringNotContainsString('PG_USER: root', $compose);
        self::assertStringNotContainsString('PG_USER=root', $compose);
        self::assertStringNotContainsString('PG_PASSWORD: ${POSTGRES_ADMIN_PASSWORD', $compose);
        self::assertStringNotContainsString('PG_PASSWORD=${POSTGRES_ADMIN_PASSWORD', $compose);

        $users = [];
        foreach (self::PG_RUNTIME_ACCOUNTS as $service => $account) {
            $section = $this->serviceSection($compose, $service);
            self::assertMatchesRegularExpression(
                '/PG_USER[=:] ?' . preg_quote($account['user'], '/') . '/',
                $section,
                $service . ' must use its own runtime account',
            );
            self::assertStringContainsString(
                $account['password'],
                $section,
                $service . ' must receive only its own password variable',
            );
            self::assertStringNotContainsString('${POSTGRES_ADMIN_PASSWORD', $section);
            self::assertUnique($users, $account['user'], 'runtime account names must be unique');
            $users[] = $account['user'];
        }

        $relay = $this->serviceSection($compose, 'user-outbox-relay');
        self::assertStringContainsString('PG_USER: user_outbox_relay', $relay);
        self::assertStringContainsString('PG_USER_OUTBOX_RELAY_PASSWORD', $relay);

        $migration = $this->serviceSection($compose, 'database-migrate');
        self::assertStringContainsString('PG_USER: migration_ddl', $migration);
        self::assertStringContainsString('PG_MIGRATION_PASSWORD', $migration);

        $messageService = $this->serviceSection($compose, 'message-service');
        self::assertStringContainsString('MONGODB_URI', $messageService);
        self::assertStringContainsString('MONGODB_DATABASE', $messageService);
        self::assertStringContainsString('MONGODB_USER=message_runtime', $messageService);
        self::assertStringContainsString('MONGODB_RUNTIME_PASSWORD', $messageService);

        $messageRelay = $this->serviceSection($compose, 'message-outbox-relay');
        self::assertStringContainsString('MONGODB_URI', $messageRelay);
        self::assertStringContainsString('MONGODB_DATABASE', $messageRelay);
        self::assertStringContainsString('MONGODB_USER: message_outbox_relay', $messageRelay);
        self::assertStringContainsString('MONGODB_RELAY_PASSWORD', $messageRelay);

        $mongoMigrate = $this->serviceSection($compose, 'mongodb-migrate');
        self::assertStringContainsString('MONGODB_URI', $mongoMigrate);
        self::assertStringContainsString('MONGODB_DATABASE', $mongoMigrate);
        self::assertStringContainsString('MONGODB_USER: message_migration', $mongoMigrate);
        self::assertStringContainsString('MONGODB_MIGRATION_PASSWORD', $mongoMigrate);
    }

    public function testEnvironmentTemplatesDeclareEveryCredentialSeparately(): void
    {
        foreach (['.env.example', '.env.production.example'] as $path) {
            $env = $this->read($path);
            self::assertStringContainsString('POSTGRES_ADMIN_PASSWORD=', $env, $path);

            foreach (self::PG_RUNTIME_ACCOUNTS as $account) {
                self::assertMatchesRegularExpression('/^' . $account['password'] . '=/m', $env, $path);
            }
            self::assertMatchesRegularExpression('/^PG_USER_OUTBOX_RELAY_PASSWORD=/m', $env, $path);
            self::assertMatchesRegularExpression('/^PG_MIGRATION_PASSWORD=/m', $env, $path);
            self::assertMatchesRegularExpression('/^MONGODB_URI=/m', $env, $path);
            self::assertMatchesRegularExpression('/^MONGODB_DATABASE=/m', $env, $path);
            foreach (['ADMIN', 'RUNTIME', 'RELAY', 'MIGRATION'] as $role) {
                self::assertMatchesRegularExpression('/^MONGODB_' . $role . '_PASSWORD=/m', $env, $path);
            }
        }
    }

    public function testInitSeparatesRuntimeDmlFromMigrationDdl(): void
    {
        $accounts = $this->read('infra/docker/postgresql/init/01-create-roles.sh');

        self::assertStringContainsString('CREATE ROLE migration_ddl', $accounts);
        foreach (self::PG_RUNTIME_ACCOUNTS as $account) {
            self::assertStringContainsString('CREATE ROLE ' . $account['user'], $accounts);
            self::assertStringContainsString('GRANT USAGE ON SCHEMA ' . $account['schema'], $accounts);
            self::assertStringContainsString('ALTER DEFAULT PRIVILEGES FOR ROLE migration_ddl IN SCHEMA ' . $account['schema'], $accounts);
        }
        self::assertStringContainsString('CREATE ROLE user_outbox_relay', $accounts);
        self::assertStringContainsString('GRANT USAGE ON SCHEMA user_db TO user_runtime, user_outbox_relay', $accounts);
        self::assertStringNotContainsString('SUPERUSER', $accounts);
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
            'services/message-service/bin/outbox-relay.php',
            'phinx.php',
        ];

        foreach ($files as $file) {
            $content = $this->read($file);
            self::assertStringNotContainsString("getenv('PG_USER') ?: 'postgres'", $content, $file);
            self::assertStringNotContainsString("getenv('PG_USER') ?: 'root'", $content, $file);
            self::assertStringNotContainsString("getenv('MYSQL_USER') ?: 'root'", $content, $file);
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
