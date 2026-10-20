<?php

declare(strict_types=1);

use Packages\Database\PostgresConnectionFactory;

$prefixValue = getenv('PG_SCHEMA_PREFIX');
$prefix = $prefixValue === false ? '' : $prefixValue;
if (preg_match('/\A[a-zA-Z0-9_]*\z/', $prefix) !== 1 || strlen($prefix) > 53) {
    throw new RuntimeException('PG_SCHEMA_PREFIX must contain ASCII letters, digits and underscores (maximum 53 bytes)');
}
$timeoutValue = getenv('PG_MIGRATION_LOCK_TIMEOUT');
$lockTimeout = filter_var($timeoutValue === false ? '30' : $timeoutValue, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0, 'max_range' => 300],
]);
if ($lockTimeout === false) {
    throw new RuntimeException('PG_MIGRATION_LOCK_TIMEOUT must be between 0 and 300 seconds');
}
$migrationUser = getenv('PG_MIGRATION_USER') ?: 'migration_ddl';
$migrationPassword = getenv('PG_MIGRATION_PASSWORD');
if ($migrationPassword === false) {
    throw new RuntimeException('PG_MIGRATION_PASSWORD is required');
}
$database = getenv('PG_DATABASE') ?: 'social_backend';
$metaSchema = $prefix . 'meta';
$pdo = PostgresConnectionFactory::fromEnvironment([
    'PG_DATABASE' => $database,
    'PG_USER' => $migrationUser,
    'PG_PASSWORD' => $migrationPassword,
    'PG_SCHEMA' => $metaSchema,
    'PG_APPLICATION_NAME' => 'social-backend-migrations',
    'PG_STATEMENT_TIMEOUT_MS' => getenv('PG_MIGRATION_STATEMENT_TIMEOUT_MS') ?: '60000',
    'PG_LOCK_TIMEOUT_MS' => getenv('PG_MIGRATION_LOCK_TIMEOUT_MS') ?: '10000',
]);

// Session-level PostgreSQL advisory lock survives Phinx's transactional DDL and
// is released automatically if this process exits or loses its connection.
$lockInput = $database . ':' . $prefix;
$lockKey = (string)hexdec(substr(hash('sha256', $lockInput), 0, 15));
$deadline = hrtime(true) + ($lockTimeout * 1_000_000_000);
$lock = $pdo->prepare('SELECT pg_try_advisory_lock(CAST(:key AS BIGINT))');
do {
    $lock->execute(['key' => $lockKey]);
    $acquired = $lock->fetchColumn();
    $isAcquired = $acquired !== false && $acquired !== null && $acquired !== 'f' && $acquired !== '0' && $acquired !== 0 && $acquired !== '';
    if ($isAcquired) {
        break;
    }
    if (hrtime(true) >= $deadline) {
        throw new RuntimeException('Could not acquire the PostgreSQL migration advisory lock');
    }
    usleep(100_000);
} while (true);
register_shutdown_function(static function () use ($pdo, $lockKey): void {
    try {
        $release = $pdo->prepare('SELECT pg_advisory_unlock(CAST(:key AS BIGINT))');
        $release->execute(['key' => $lockKey]);
    } catch (Throwable) {
        // Closing the non-persistent PDO also releases the session lock.
    }
});

return [
    'paths' => ['migrations' => __DIR__ . '/database/postgresql/migrations'],
    'environments' => [
        'default_environment' => 'default',
        'default' => [
            'adapter' => 'pgsql',
            'name' => $database,
            'connection' => $pdo,
            'schema' => $metaSchema,
            'migration_table' => 'phinxlog',
            'schema_prefix' => $prefix,
        ],
    ],
];
