<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Packages\Database\EloquentConnection;
use Packages\IdGenerator\Snowflake;
use Services\AuthService\Domain\Auth\ExternalIdentity;
use Services\AuthService\Domain\Auth\SocialProvider;
use Services\AuthService\Infrastructure\Repository\EloquentSocialIdentityRepository;

if ($argc < 8) {
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
    // 替代已移除的 BEFORE INSERT 触发器：工作进程在插入 social_identities 之前等待，直到测试释放启动文件，使两个进程
    // 都先完成未命中的预检查 SELECT，再由数据库唯一键决定竞争结果。截止时间可避免释放信号丢失导致永久阻塞。
    $released = false;
    $connection->beforeExecuting(static function (string $query) use ($readyPath, $startPath, &$released): void {
        if ($released || !str_starts_with($query, 'insert into `social_identities`')) {
            return;
        }

        $released = true;
        file_put_contents($readyPath, 'ready');

        $deadline = microtime(true) + 5.0;
        while (!is_file($startPath) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (!is_file($startPath)) {
            throw new RuntimeException('Timed out waiting for the reserve insert barrier');
        }
        usleep(100_000);
    });

    $identity = new ExternalIdentity(
        provider: SocialProvider::WECHAT,
        providerAppId: $argv[6],
        identityNamespace: 'wechat-main',
        openId: $argv[4],
        unionId: $argv[5] !== '' ? $argv[5] : null,
    );
    $reserved = (new EloquentSocialIdentityRepository($pdo, new Snowflake((int)$argv[7])))
        ->reserve($identity);
    file_put_contents($resultPath, 'ok|' . $reserved->id);
} catch (Throwable $e) {
    file_put_contents($resultPath, 'error|' . $e::class . '|' . $e->getCode());
}

exit(0);
