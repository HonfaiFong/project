<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Packages\GrpcCommon\Health\RoadRunnerHealthService;
use Packages\IdGenerator\Snowflake;
use Packages\IdGenerator\WorkerId;
use Packages\Logging\JsonLogger;
use Contracts\Gen\Auth\V1\AuthServiceInterface;
use Contracts\Gen\Common\V1\HealthServiceInterface;
use Services\AuthService\Application\Service\AuthApplicationService;
use Services\AuthService\Infrastructure\Auth\LoginCredentialVerifier;
use Services\AuthService\Infrastructure\Cache\RedisAccessTokenRevocationStore;
use Services\AuthService\Infrastructure\Client\GrpcUserDirectoryClient;
use Services\AuthService\Infrastructure\Repository\PdoAuthRepository;
use Services\AuthService\Transport\Grpc\AuthGrpcHandler;
use Services\AuthService\Transport\Grpc\RoadRunnerAuthService;
use Spiral\RoadRunner\GRPC\Invoker;
use Spiral\RoadRunner\GRPC\Server;
use Spiral\RoadRunner\Worker;

$logger = new JsonLogger('auth-service', getenv('SERVICE_INSTANCE_ID') ?: 'auth-01');

// Database Connection
$mysqlHost = getenv('MYSQL_HOST') ?: '127.0.0.1';
$mysqlPort = getenv('MYSQL_PORT') ?: '3306';
$mysqlUser = getenv('MYSQL_USER') ?: 'root';
$mysqlPass = getenv('MYSQL_PASSWORD') ?: 'root_password';
$mysqlDb   = getenv('AUTH_DB_NAME') ?: 'auth_db';

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

$redisHost = getenv('REDIS_HOST') ?: '127.0.0.1';
$redisPort = (int)(getenv('REDIS_PORT') ?: 6379);
$redisPassword = (string)(getenv('REDIS_PASSWORD') ?: '');
$redis = null;

for ($i = 0; $i < $maxRetries; $i++) {
    try {
        $candidate = new Redis();
        if (!$candidate->connect($redisHost, $redisPort, 1.0)) {
            throw new RuntimeException('Redis connection failed');
        }
        if ($redisPassword !== '' && !$candidate->auth($redisPassword)) {
            throw new RuntimeException('Redis authentication failed');
        }
        $candidate->ping();
        $redis = $candidate;
        $logger->info("Connected to Redis successfully: {$redisHost}:{$redisPort}");
        break;
    } catch (Throwable $e) {
        $logger->warning("Waiting for Redis ({$redisHost}:{$redisPort})... " . $e->getMessage());
        sleep(2);
    }
}

if (!$redis) {
    $logger->error("Could not connect to Redis after retries, exiting.");
    exit(1);
}

$snowflake = new Snowflake(WorkerId::resolve());
$authRepo = new PdoAuthRepository($pdo, $snowflake);
$jwtSecret = trim((string)getenv('JWT_SECRET'));
$wsSecret = trim((string)getenv('WS_TICKET_SECRET'));
if (strlen($jwtSecret) < 32 || strlen($wsSecret) < 32) {
    throw new RuntimeException('JWT_SECRET and WS_TICKET_SECRET must each be at least 32 bytes');
}
$accessTokenRevocationStore = new RedisAccessTokenRevocationStore($redis);
$credentialVerifier = LoginCredentialVerifier::fromEnvironment();
if (!$credentialVerifier->isDevelopment() && !$credentialVerifier->hasProvider()) {
    $logger->warning('AuthService running in production mode without configured Credential Provider. Real logins will fail until providers are registered.');
}
$userServiceTarget = getenv('GRPC_USER_SERVICE') ?: 'user-service:50052';
$userDirectory = new GrpcUserDirectoryClient($userServiceTarget);

$appService = new AuthApplicationService(
    authRepo: $authRepo,
    jwtSecret: $jwtSecret,
    wsTicketSecret: $wsSecret,
    accessTokenRevocationStore: $accessTokenRevocationStore,
    credentialVerifier: $credentialVerifier,
    userDirectory: $userDirectory
);
$handler = new AuthGrpcHandler($appService, $logger);
$grpcService = new RoadRunnerAuthService($handler);
$server = new Server(new Invoker());
$server->registerService(AuthServiceInterface::class, $grpcService);
$server->registerService(HealthServiceInterface::class, new RoadRunnerHealthService());

$logger->info('AuthService started with RoadRunner gRPC worker');
$server->serve(Worker::create());
