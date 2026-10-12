<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Packages\IdGenerator\Snowflake;
use Packages\Outbox\OutboxRepository;
use Services\SocialService\Application\Service\SocialApplicationService;
use Services\SocialService\Infrastructure\Repository\PdoSocialRepository;
use Tests\Support\FakePersonaDirectory;
use Tests\Support\FakeResourceAccess;

if ($argc < 9) {
    exit(2);
}

$readyPath = $argv[1];
$resultPath = $argv[2];
$startPath = $argv[3];
$userId = $argv[4];
$personaId = $argv[5];
$targetType = (int)$argv[6];
$targetId = $argv[7];
$workerId = (int)$argv[8];

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
            PDO::ATTR_TIMEOUT => 5,
            // 与测试主连接一致：该模式下“已存在行”也报告受影响行数为 1。
            PDO::MYSQL_ATTR_FOUND_ROWS => true,
        ],
    );

    $snowflake = new Snowflake($workerId);
    $service = new SocialApplicationService(
        repository: new PdoSocialRepository($pdo),
        personas: new FakePersonaDirectory(),
        resources: new FakeResourceAccess(),
        snowflake: $snowflake,
        outbox: new OutboxRepository($pdo, $snowflake),
    );

    file_put_contents($readyPath, 'ready');

    $deadline = microtime(true) + 5.0;
    while (!is_file($startPath) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (!is_file($startPath)) {
        throw new RuntimeException('Timed out waiting for the like barrier');
    }

    $result = $service->like($userId, $personaId, $targetType, $targetId);
    file_put_contents($resultPath, 'ok|' . ($result ? '1' : '0'));
} catch (Throwable $e) {
    file_put_contents($resultPath, 'error|' . $e::class . '|' . $e->getCode());
}

exit(0);
