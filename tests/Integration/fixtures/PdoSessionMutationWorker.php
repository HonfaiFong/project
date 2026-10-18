<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Packages\Database\EloquentConnection;
use Packages\IdGenerator\Snowflake;
use Services\AuthService\Infrastructure\Repository\EloquentAuthRepository;

[$script, $readyPath, $resultPath, $operation, $tokenHash, $replacementHash] = $argv;

try {
    $database = (string)getenv('SOCIAL_TEST_MYSQL_DATABASE');
    if (!str_starts_with($database, 'social_test_')) {
        throw new RuntimeException('Expected an isolated test database');
    }
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        getenv('SOCIAL_TEST_MYSQL_HOST') ?: '127.0.0.1',
        (int)(getenv('SOCIAL_TEST_MYSQL_PORT') ?: 3306), $database),
        getenv('SOCIAL_TEST_MYSQL_USER') ?: 'root', getenv('SOCIAL_TEST_MYSQL_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
    EloquentConnection::fromPdo($pdo)->beforeExecuting(static function (string $query) use ($readyPath): void {
        if (str_contains($query, 'from `devices`') && str_contains($query, 'for update')) {
            file_put_contents($readyPath, 'locking');
        }
    });
    $repository = new EloquentAuthRepository($pdo, new Snowflake(1022));
    $success = $operation === 'logout'
        ? $repository->revokeRefreshToken($tokenHash)
        : $repository->rotateRefreshToken($tokenHash, $replacementHash, '2099-01-01 00:00:00') !== null;
    file_put_contents($resultPath, $success ? 'ok|1' : 'ok|0');
} catch (Throwable $exception) {
    file_put_contents($resultPath, 'error|' . $exception->getMessage());
}
