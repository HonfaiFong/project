<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Packages\Database\EloquentConnection;
use Packages\IdGenerator\Snowflake;
use Services\AuthService\Infrastructure\Repository\EloquentAuthRepository;

if ($argc < 7) {
    exit(2);
}

$readyPath = $argv[1];
$resultPath = $argv[2];
$startPath = $argv[3];

try {
    $database = (string)getenv('SOCIAL_TEST_MYSQL_DATABASE');
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            getenv('SOCIAL_TEST_MYSQL_HOST') ?: '127.0.0.1',
            (int)(getenv('SOCIAL_TEST_MYSQL_PORT') ?: 3306),
            $database,
        ),
        getenv('SOCIAL_TEST_MYSQL_USER') ?: 'root',
        getenv('SOCIAL_TEST_MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 2,
        ],
    );
    $connection = EloquentConnection::fromPdo($pdo);
    // Replaces the removed BEFORE INSERT trigger: the worker parks directly
    // before its devices insert until the test releases the start file, so
    // both workers have already missed the pre-check select when the database
    // unique key decides the race. The deadline keeps a lost release from
    // blocking the worker forever.
    $released = false;
    $connection->beforeExecuting(static function (string $query) use ($readyPath, $startPath, &$released): void {
        if ($released || !str_starts_with($query, 'insert into `devices`')) {
            return;
        }

        $released = true;
        file_put_contents($readyPath, 'ready');

        $deadline = microtime(true) + 5.0;
        while (!is_file($startPath) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (!is_file($startPath)) {
            throw new RuntimeException('Timed out waiting for the device insert barrier');
        }
        usleep(100_000);
    });

    $device = (new EloquentAuthRepository($pdo, new Snowflake((int)$argv[6])))->findOrCreateDevice(
        userId: $argv[4],
        deviceUuid: $argv[5],
        platform: 'ios',
        appVersion: '1.0.0',
        pushToken: null,
        ip: null,
    );
    file_put_contents($resultPath, 'ok|' . $device->id);
} catch (Throwable $e) {
    file_put_contents($resultPath, 'error|' . $e::class . '|' . $e->getCode() . '|' . $e->getMessage());
}

exit(0);
