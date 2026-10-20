<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Packages\Database\EloquentConnection;
use Packages\Database\PostgresConnectionFactory;
use Packages\GrpcCommon\Health\RoadRunnerHealthService;
use Packages\GrpcCommon\InternalRpcAuthorizer;
use Packages\GrpcCommon\LeaseMaintainingRelay;
use Packages\IdGenerator\Snowflake;
use Packages\IdGenerator\WorkerId;
use Packages\Logging\JsonLogger;
use Packages\Observability\MetricsHttpServer;
use Packages\Outbox\Model\OutboxEvent;
use Packages\Outbox\OutboxRepository;
use Packages\Phone\PhoneNormalizer;
use Contracts\Gen\Common\V1\HealthServiceInterface;
use Contracts\Gen\User\V1\FindOrCreateByPhoneRequest;
use Contracts\Gen\User\V1\FindOrCreateBySocialIdentityRequest;
use Contracts\Gen\User\V1\GetUserRequest;
use Contracts\Gen\User\V1\ValidatePersonaOwnershipRequest;
use Contracts\Gen\User\V1\ResolvePersonaModeRequest;
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

MetricsHttpServer::startInBackground();

$logger = new JsonLogger('user-service', getenv('SERVICE_INSTANCE_ID') ?: 'user-01');

// 启动重试循环有意沿用其他服务的工作进程启动方式，使每个服务都能独立部署。
/** @noinspection DuplicatedCode */
$maxRetries = 15;
$pdo = null;

for ($i = 0; $i < $maxRetries; $i++) {
    try {
        $pdo = PostgresConnectionFactory::fromEnvironment();
        $logger->info('Connected to PostgreSQL', ['schema' => getenv('PG_SCHEMA') ?: 'user_db']);
        break;
    } catch (Throwable $e) {
        $logger->warning('Waiting for PostgreSQL', ['exception' => $e::class]);
        sleep(2);
    }
}

if (!$pdo) {
    $logger->error('Could not connect to PostgreSQL after retries, exiting.');
    exit(1);
}

// 缺少工作节点 ID 或无法使用 CSPRNG 时，须终止启动；进程以非零状态退出，使监管器能观察到崩溃。
/** @noinspection PhpUnhandledExceptionInspection */
$snowflake = new Snowflake(WorkerId::resolve());
$userRepo = new EloquentUserRepository($pdo);
$personaRepo = new EloquentPersonaRepository($pdo);
$transactionManager = new EloquentTransactionManager($pdo);
$outbox = new OutboxRepository($pdo, $snowflake);
// Eloquent 首次使用时才初始化各模型；若不预热，新工作进程的首个 RPC 需承担约 250 毫秒初始化开销，会超出网关 gRPC
// 截止时间。
EloquentConnection::warmup(
    $pdo,
    UserModel::class,
    PersonaModel::class,
    OutboxEvent::class,
);

$phoneNormalizer = new PhoneNormalizer(trim((string)(getenv('PHONE_DEFAULT_REGION') ?: 'CN')));
// 首次 Protobuf 编码会初始化共享描述符池（约 80 毫秒），首次手机号规范化会加载国家元数据；这些开销应在工作进程启
// 动时承担。
(new FindOrCreateByPhoneRequest())->serializeToString();
(new FindOrCreateBySocialIdentityRequest())->serializeToString();
(new GetUserRequest())->serializeToString();
(new ValidatePersonaOwnershipRequest())->serializeToString();
(new ResolvePersonaModeRequest())->serializeToString();
$phoneNormalizer->normalize('+8613800000000');
$gatewayRpcToken = trim((string)(getenv('INTERNAL_RPC_GATEWAY_USER_TOKEN') ?: ''));
$authRpcToken = trim((string)(getenv('INTERNAL_RPC_AUTH_USER_TOKEN') ?: ''));
$contentRpcToken = trim((string)(getenv('INTERNAL_RPC_CONTENT_USER_TOKEN') ?: ''));
$socialRpcToken = trim((string)(getenv('INTERNAL_RPC_SOCIAL_USER_TOKEN') ?: ''));
$chatRpcToken = trim((string)(getenv('INTERNAL_RPC_CHAT_USER_TOKEN') ?: ''));
$internalRpcAuthorizer = new InternalRpcAuthorizer(
    callerTokens: [
        'api-gateway' => $gatewayRpcToken,
        'auth-service' => $authRpcToken,
        'content-service' => $contentRpcToken,
        'social-service' => $socialRpcToken,
        'chat-service' => $chatRpcToken,
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
            'ResolvePersonaMode',
            'BatchGetPersonas',
            'UpdatePersona',
            'ListPersonas',
            'Check',
        ],
        'auth-service' => ['FindOrCreateByPhone', 'FindOrCreateBySocialIdentity', 'GetUser'],
        'content-service' => ['GetPersona', 'ResolvePersonaMode', 'ValidatePersonaOwnership'],
        'social-service' => ['GetUser', 'GetPersona', 'ResolvePersonaMode', 'ValidatePersonaOwnership'],
        'chat-service' => ['GetPersona', 'ResolvePersonaMode', 'ValidatePersonaOwnership'],
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
$server->serve(LeaseMaintainingRelay::createWorker());
