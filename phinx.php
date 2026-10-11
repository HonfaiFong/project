<?php

declare(strict_types=1);

// 咨询锁与 Phinx 共用同一 PDO 会话；MySQL DDL 会隐式提交，因此事务无法串行化整个迁移批次。
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

$password = getenv('MYSQL_MIGRATION_PASSWORD');
if ($password === false) {
    // Compose passes the migration secret as MYSQL_PASSWORD for Phinx; direct
    // invocations can use the explicit MYSQL_MIGRATION_PASSWORD name instead.
    $password = getenv('MYSQL_PASSWORD');
}
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', getenv('MYSQL_HOST') ?: '127.0.0.1', (int)(getenv('MYSQL_PORT') ?: 3306)),
    getenv('MYSQL_USER') ?: 'migration_ddl',
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
        // 关闭非持久 PDO 连接也会释放此锁。
    }
});

// 此处仅初始化迁移记录数据库，标识符已在上方校验；所有业务表结构变更均应通过版本化迁移完成。
$metaDatabase = $prefix . 'meta';
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$metaDatabase` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$metaDatabase`");

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
