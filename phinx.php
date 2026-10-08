<?php

declare(strict_types=1);

// The advisory lock and Phinx share one PDO session. MySQL DDL commits
// implicitly, so a transaction cannot serialize a whole migration batch.
$prefixValue = getenv('MYSQL_SCHEMA_PREFIX');
$prefix = $prefixValue === false ? '' : $prefixValue;
if (!preg_match('/\A[a-zA-Z0-9_]*\z/', $prefix) || strlen($prefix) > 54) {
    throw new RuntimeException('MYSQL_SCHEMA_PREFIX must contain only letters, digits and underscores (maximum 54 characters)');
}
$timeoutValue = getenv('MYSQL_MIGRATION_LOCK_TIMEOUT');
$lockTimeout = filter_var($timeoutValue === false ? '30' : $timeoutValue, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0, 'max_range' => 300],
]);
if ($lockTimeout === false) {
    throw new RuntimeException('MYSQL_MIGRATION_LOCK_TIMEOUT must be between 0 and 300 seconds');
}

$password = getenv('MYSQL_PASSWORD');
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', getenv('MYSQL_HOST') ?: '127.0.0.1', (int)(getenv('MYSQL_PORT') ?: 3306)),
    getenv('MYSQL_USER') ?: 'root',
    $password === false ? '' : $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 10,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ],
);
$lockName = 'social_migrations_' . substr(hash('sha256', $prefix), 0, 40);
$lock = $pdo->prepare('SELECT GET_LOCK(:name, :timeout)');
$lock->execute(['name' => $lockName, 'timeout' => $lockTimeout]);
if ((int)$lock->fetchColumn() !== 1) {
    throw new RuntimeException('Could not acquire the database migration lock; another migration may be running');
}
register_shutdown_function(static function () use ($pdo, $lockName): void {
    try {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $release->execute(['name' => $lockName]);
    } catch (Throwable) {
        // Closing the non-persistent PDO connection also releases this lock.
    }
});

// Only the tracking database is bootstrapped here. The identifier is validated
// above; all business schema changes belong to versioned migrations.
$metaDatabase = $prefix . 'meta';
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$metaDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$metaDatabase}`");

return [
    'paths' => ['migrations' => __DIR__ . '/database/migrations'],
    'environments' => [
        'default_environment' => 'default',
        'default' => [
            'adapter' => 'mysql',
            'name' => $metaDatabase,
            'connection' => $pdo,
            'migration_table' => $metaDatabase . '.phinxlog',
            'schema_prefix' => $prefix,
        ],
    ],
];
