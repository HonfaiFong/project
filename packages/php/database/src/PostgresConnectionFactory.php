<?php

declare(strict_types=1);

namespace Packages\Database;

use PDO;
use RuntimeException;

/** Creates a service-scoped PostgreSQL PDO using an explicit schema search path. */
final class PostgresConnectionFactory
{
    /** @param array<string, scalar|null> $overrides */
    public static function fromEnvironment(array $overrides = []): PDO
    {
        $value = static function (string $name, string $default = '') use ($overrides): string {
            if (array_key_exists($name, $overrides)) {
                $candidate = $overrides[$name];
                return $candidate === null ? $default : (string) $candidate;
            }
            $env = getenv($name);
            return $env === false ? $default : $env;
        };

        $host = $value('PG_HOST', '127.0.0.1');
        if ($host === '' || preg_match('/\A[a-zA-Z0-9_.:\-\[\]]+\z/', $host) !== 1) {
            throw new RuntimeException('PG_HOST must be a hostname or IP address');
        }
        $port = self::boundedInteger($value('PG_PORT', '5432'), 'PG_PORT', 1, 65535);
        $database = $value('PG_DATABASE', 'social_backend');
        $user = $value('PG_USER');
        $password = $value('PG_PASSWORD');
        $schema = $value('PG_SCHEMA');
        if ($user === '' || $schema === '') {
            throw new RuntimeException('PG_USER and PG_SCHEMA are required');
        }
        self::identifier($database, 'PG_DATABASE');
        self::identifier($schema, 'PG_SCHEMA');

        $connectTimeout = self::boundedInteger($value('PG_CONNECT_TIMEOUT_SECONDS', '5'), 'PG_CONNECT_TIMEOUT_SECONDS', 1, 60);
        $applicationName = preg_replace('/[^A-Za-z0-9_.-]/', '-', $value('PG_APPLICATION_NAME', $schema)) ?: $schema;
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s;connect_timeout=%d;application_name=%s', $host, $port, $database, $connectTimeout, $applicationName),
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ],
        );

        $path = '"' . $schema . '", pg_catalog';
        $pdo->prepare("SELECT set_config('search_path', :path, false)")->execute(['path' => $path]);
        $pdo->exec("SET TIME ZONE 'UTC'");
        $pdo->exec("SET default_transaction_isolation TO 'read committed'");
        $statementTimeout = self::boundedInteger($value('PG_STATEMENT_TIMEOUT_MS', '5000'), 'PG_STATEMENT_TIMEOUT_MS', 1, 60000);
        $lockTimeout = self::boundedInteger($value('PG_LOCK_TIMEOUT_MS', '1000'), 'PG_LOCK_TIMEOUT_MS', 1, 60000);
        $pdo->prepare("SELECT set_config('statement_timeout', :timeout, false)")->execute(['timeout' => $statementTimeout . 'ms']);
        $pdo->prepare("SELECT set_config('lock_timeout', :timeout, false)")->execute(['timeout' => $lockTimeout . 'ms']);

        return $pdo;
    }

    private static function identifier(string $value, string $name): void
    {
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,62}\z/', $value) !== 1) {
            throw new RuntimeException($name . ' must be a valid PostgreSQL identifier');
        }
    }

    private static function boundedInteger(string $value, string $name, int $min, int $max): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false || $parsed < $min || $parsed > $max) {
            throw new RuntimeException($name . ' is outside its supported range');
        }
        return $parsed;
    }
}
