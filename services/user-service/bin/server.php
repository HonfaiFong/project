<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Packages\Database\EloquentConnection;
use Packages\GrpcCommon\Health\RoadRunnerHealthService;
use Packages\GrpcCommon\InternalRpcAuthorizer;
use Packages\IdGenerator\Snowflake;
use Packages\IdGenerator\WorkerId;
use Packages\Logging\JsonLogger;
use Packages\Outbox\Model\OutboxEvent;
use Packages\Outbox\OutboxRepository;
use Packages\Phone\PhoneNormalizer;
use Contracts\Gen\Common\V1\HealthServiceInterface;
use Contracts\Gen\User\V1\FindOrCreateByPhoneRequest;
use Contracts\Gen\User\V1\FindOrCreateBySocialIdentityRequest;
use Contracts\Gen\User\V1\GetUserRequest;
use Contracts\Gen\User\V1\ValidatePersonaOwnershipRequest;
use Contracts\Gen\User\V1\UserServiceInterface;
use Services\UserService\Application\Service\UserApplicationService;
use Services\UserService\Infrastructure\Model\PersonaModel;
use Services\UserService\Infrastructure\Model\UserModel;
use Services\UserService\Infrastructure\Repository\EloquentPersonaRepository;
use Services\UserService\Infrastructure\Repository\EloquentTransactionManager;
use Services\UserService\Infrastructure\Repository\EloquentUserRepository;
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

// The startup retry loop intentionally mirrors the sibling services' worker
// bootstrap so every service stays independently deployable.
/** @noinspection DuplicatedCode */
$maxRetries = 15;
$pdo = null;

for ($i = 0; $i < $maxRetries; $i++) {
    try {
        $dsn = "mysql:host=$mysqlHost;port=$mysqlPort;dbname=$mysqlDb;charset=utf8mb4";
        $pdo = new PDO($dsn, $mysqlUser, $mysqlPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $logger->info("Connected to MySQL successfully: $mysqlDb");
        break;
    } catch (Throwable $e) {
        $logger->warning("Waiting for MySQL ($mysqlDb)... " . $e->getMessage());
        sleep(2);
    }
}

if (!$pdo) {
    $logger->error("Could not connect to MySQL after retries, exiting.");
    exit(1);
}

// A missing worker id or an unreadable CSPRNG must abort startup: the process
// exits non-zero and the supervisor observes the crash.
/** @noinspection PhpUnhandledExceptionInspection */
$snowflake = new Snowflake(WorkerId::resolve());
$userRepo = new EloquentUserRepository($pdo);
$personaRepo = new EloquentPersonaRepository($pdo);
$transactionManager = new EloquentTransactionManager($pdo);
$outbox = new OutboxRepository($pdo, $snowflake);
// Eloquent boots each model class lazily on first use; without this warmup the
// first RPC on a fresh worker pays ~250ms of boot cost and misses the gateway
// gRPC deadline.
EloquentConnection::warmup(
    $pdo,
    UserModel::class,
    PersonaModel::class,
    OutboxEvent::class,
);

$phoneNormalizer = new PhoneNormalizer(trim((string)(getenv('PHONE_DEFAULT_REGION') ?: 'CN')));
// The first protobuf encode initializes the shared descriptor pool (~80ms) and
// the first phone normalization loads country metadata; pay both at worker
// start instead of on the first RPC.
(new FindOrCreateByPhoneRequest())->serializeToString();
(new FindOrCreateBySocialIdentityRequest())->serializeToString();
(new GetUserRequest())->serializeToString();
(new ValidatePersonaOwnershipRequest())->serializeToString();
$phoneNormalizer->normalize('+8613800000000');
$gatewayRpcToken = trim((string)(getenv('INTERNAL_RPC_GATEWAY_USER_TOKEN') ?: ''));
$authRpcToken = trim((string)(getenv('INTERNAL_RPC_AUTH_USER_TOKEN') ?: ''));
$internalRpcAuthorizer = new InternalRpcAuthorizer(
    callerTokens: [
        'api-gateway' => $gatewayRpcToken,
        'auth-service' => $authRpcToken,
    ],
    allowedMethodsByCaller: [
        // Keep the gateway's public surface explicit so adding an internal
        // RPC cannot accidentally widen either caller's privileges.
        'api-gateway' => [
            'FindOrCreateByPhone',
            'FindOrCreateBySocialIdentity',
            'GetUser',
            'BatchGetUsers',
            'UpdateUser',
            'CreatePersona',
            'GetPersona',
            'ValidatePersonaOwnership',
            'BatchGetPersonas',
            'UpdatePersona',
            'ListPersonas',
            'Check',
        ],
        'auth-service' => ['FindOrCreateByPhone', 'FindOrCreateBySocialIdentity', 'GetUser'],
    ],
);

$appService = new UserApplicationService(
    $userRepo,
    $personaRepo,
    $snowflake,
    $transactionManager,
    $outbox,
    $phoneNormalizer,
);
$grpcService = new UserGrpcService($appService, $logger, $internalRpcAuthorizer);
$server = new Server(new Invoker());
$server->registerService(UserServiceInterface::class, $grpcService);
$server->registerService(
    HealthServiceInterface::class,
    new RoadRunnerHealthService(authorizer: $internalRpcAuthorizer),
);

$logger->info('UserService started with RoadRunner gRPC worker');
$server->serve(Worker::create());
