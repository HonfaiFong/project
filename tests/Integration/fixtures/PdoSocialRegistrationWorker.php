<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Packages\Database\EloquentConnection;
use Packages\IdGenerator\Snowflake;
use Packages\Outbox\OutboxRepository;
use Packages\Phone\PhoneNormalizer;
use Services\UserService\Application\Service\UserApplicationService;
use Services\UserService\Infrastructure\Repository\EloquentPersonaRepository;
use Services\UserService\Infrastructure\Repository\EloquentTransactionManager;
use Services\UserService\Infrastructure\Repository\EloquentUserRepository;

if ($argc < 9) {
    exit(2);
}

$readyPath = $argv[1];
$resultPath = $argv[2];
$startPath = $argv[3];
$registrationKey = $argv[4];
$registerChannel = $argv[5];
$insertReadyPath = $argv[7];
$insertReleasePath = $argv[8];

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
        ],
    );
    file_put_contents($readyPath, 'ready');

    $deadline = microtime(true) + 5.0;
    while (!is_file($startPath) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (!is_file($startPath)) {
        throw new RuntimeException('Timed out waiting for the registration barrier');
    }

    // Replaces the removed "slow_user_insert" trigger: every worker parks here,
    // inside its own transaction and just before its INSERT into users, until
    // the test has seen all workers arrive and releases them together, so the
    // unique registration key -- not the pre-check read -- serializes the race.
    EloquentConnection::fromPdo($pdo)->beforeExecuting(
        static function (string $query) use ($insertReadyPath, $insertReleasePath): void {
            if (!str_starts_with($query, 'insert ') || !str_contains($query, '`users`')) {
                return;
            }
            file_put_contents($insertReadyPath, 'ready');
            $deadline = microtime(true) + 20.0;
            while (!is_file($insertReleasePath) && microtime(true) < $deadline) {
                usleep(1000);
            }
            if (!is_file($insertReleasePath)) {
                throw new RuntimeException('Timed out waiting for the insert barrier release');
            }
        },
    );

    $snowflake = new Snowflake((int)$argv[6]);
    $service = new UserApplicationService(
        userRepo: new EloquentUserRepository($pdo),
        personaRepo: new EloquentPersonaRepository($pdo),
        snowflake: $snowflake,
        transactionManager: new EloquentTransactionManager($pdo),
        outbox: new OutboxRepository($pdo, $snowflake),
        phoneNormalizer: new PhoneNormalizer(),
    );

    $result = $service->findOrCreateUserBySocialIdentity($registrationKey, $registerChannel);
    file_put_contents(
        $resultPath,
        'ok|' . $result['user']->id . '|' . ($result['is_new_user'] ? '1' : '0'),
    );
} catch (Throwable $e) {
    file_put_contents($resultPath, 'error|' . $e::class . '|' . $e->getCode());
}

exit(0);
