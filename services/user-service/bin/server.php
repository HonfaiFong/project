<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Packages\IdGenerator\Snowflake;
use Packages\Logging\JsonLogger;
use Packages\Outbox\OutboxRepository;
use Contracts\Gen\User\V1\UserServiceInterface;
use Services\UserService\Application\Service\UserApplicationService;
use Services\UserService\Infrastructure\Repository\PdoPersonaRepository;
use Services\UserService\Infrastructure\Repository\PdoTransactionManager;
use Services\UserService\Infrastructure\Repository\PdoUserRepository;
use Services\UserService\Transport\Grpc\UserGrpcService;
use Spiral\RoadRunner\GRPC\Invoker;
use Spiral\RoadRunner\GRPC\Server;
use Spiral\RoadRunner\Worker;

$logger = new JsonLogger('user-service', getenv('SERVICE_INSTANCE_ID') ?: 'user-01');

// Database Connection
$mysqlHost = getenv('MYSQL_HOST') ?: '127.0.0.1';
$mysqlPort = getenv('MYSQL_PORT') ?: '3306';
$mysqlUser = getenv('MYSQL_USER') ?: 'root';
$mysqlPass = getenv('MYSQL_PASSWORD') ?: 'root_password';
$mysqlDb   = getenv('USER_DB_NAME') ?: 'user_db';

$maxRetries = 15;
$pdo = null;

for ($i = 0; $i < $maxRetries; $i++) {
    try {
        $dsn = "mysql:host={$mysqlHost};port={$mysqlPort};dbname={$mysqlDb};charset=utf8mb4";
        $pdo = new PDO($dsn, $mysqlUser, $mysqlPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $logger->info("Connected to MySQL successfully: {$mysqlDb}");
        break;
    } catch (\Throwable $e) {
        $logger->warning("Waiting for MySQL ({$mysqlDb})... " . $e->getMessage());
        sleep(2);
    }
}

if (!$pdo) {
    $logger->error("Could not connect to MySQL after retries, exiting.");
    exit(1);
}

$snowflake = new Snowflake((int)(getenv('WORKER_ID') ?: 2));
$userRepo = new PdoUserRepository($pdo);
$personaRepo = new PdoPersonaRepository($pdo);
$transactionManager = new PdoTransactionManager($pdo);
$outbox = new OutboxRepository($pdo, $snowflake);

$appService = new UserApplicationService($userRepo, $personaRepo, $snowflake, $transactionManager, $outbox);
$grpcService = new UserGrpcService($appService, $logger);
$server = new Server(new Invoker());
$server->registerService(UserServiceInterface::class, $grpcService);

$logger->info('UserService started with RoadRunner gRPC worker');
$server->serve(Worker::create());
