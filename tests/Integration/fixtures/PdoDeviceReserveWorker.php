<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Packages\IdGenerator\Snowflake;
use Services\AuthService\Infrastructure\Repository\PdoAuthRepository;

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
    file_put_contents($readyPath, 'ready');

    $deadline = microtime(true) + 3.0;
    while (!is_file($startPath) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (!is_file($startPath)) {
        throw new RuntimeException('Timed out waiting for device reserve barrier');
    }

    $device = (new PdoAuthRepository($pdo, new Snowflake((int)$argv[6])))->findOrCreateDevice(
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
