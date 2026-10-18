<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Contracts\Gen\Common\V1\HealthServiceInterface;
use Contracts\Gen\Message\V1\GetMessagesRequest;
use Contracts\Gen\Message\V1\MessageServiceInterface;
use Contracts\Gen\Message\V1\PublicSendMessageRequest;
use Contracts\Gen\Message\V1\SyncMessagesRequest;
use Packages\GrpcCommon\Health\RoadRunnerHealthService;
use Packages\GrpcCommon\InternalRpcAuthorizer;
use Packages\GrpcCommon\LeaseMaintainingRelay;
use Packages\IdGenerator\Snowflake;
use Packages\IdGenerator\WorkerId;
use Packages\Identity\AnonymousAuthorProjector;
use Packages\Identity\AnonymousReferenceFactory;
use Packages\Logging\JsonLogger;
use Packages\Observability\MetricsHttpServer;
use Packages\Outbox\OutboxRepository;
use Services\MessageService\Application\MessageApplicationService;
use Services\MessageService\Infrastructure\GrpcChatMemberSnapshotClient;
use Services\MessageService\Infrastructure\PdoMessageRepository;
use Services\MessageService\Infrastructure\SnowflakeIdGenerator;
use Services\MessageService\Transport\Grpc\RoadRunnerMessageService;
use Spiral\RoadRunner\GRPC\Invoker;
use Spiral\RoadRunner\GRPC\Server;

MetricsHttpServer::startInBackground();

$logger = new JsonLogger('message-service', getenv('SERVICE_INSTANCE_ID') ?: 'message-01');
$mysqlHost = getenv('MYSQL_HOST') ?: '127.0.0.1';
$mysqlPort = getenv('MYSQL_PORT') ?: '3306';
$mysqlUser = getenv('MYSQL_USER') ?: 'message_runtime';
$mysqlPass = getenv('MYSQL_PASSWORD') ?: '';
$mysqlDb = getenv('MESSAGE_DB_NAME') ?: 'message_db';

$pdo = null;
for ($attempt = 0; $attempt < 15; $attempt++) {
    try {
        $pdo = new PDO(
            "mysql:host=$mysqlHost;port=$mysqlPort;dbname=$mysqlDb;charset=utf8mb4",
            $mysqlUser,
            $mysqlPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
        break;
    } catch (Throwable $e) {
        $logger->warning('Waiting for MySQL (' . $mysqlDb . ')... ' . $e->getMessage());
        sleep(2);
    }
}
if (!$pdo instanceof PDO) {
    $logger->error('Could not connect to MySQL after retries, exiting.');
    exit(1);
}

$anonymousKey = trim((string)getenv('ANONYMOUS_IDENTITY_KEY'));
if ($anonymousKey === '') {
    throw new RuntimeException('ANONYMOUS_IDENTITY_KEY is required');
}
$snowflake = new Snowflake(WorkerId::resolve());
$worker = new SnowflakeIdGenerator($snowflake);
$application = new MessageApplicationService(
    repository: new PdoMessageRepository($pdo),
    chat: new GrpcChatMemberSnapshotClient(
        target: getenv('GRPC_CHAT_SERVICE') ?: 'chat-service:50055',
        serviceToken: trim((string)(getenv('GRPC_CHAT_SERVICE_TOKEN') ?: '')) ?: null,
        timeoutMs: (int)(getenv('GRPC_CHAT_MEMBER_TIMEOUT_MS') ?: 250),
    ),
    ids: $worker,
    projector: new AnonymousAuthorProjector(new AnonymousReferenceFactory($anonymousKey)),
    outbox: new OutboxRepository($pdo, $snowflake),
);

$gatewayToken = trim((string)(getenv('INTERNAL_RPC_GATEWAY_MESSAGE_TOKEN') ?: ''));
$authorizer = new InternalRpcAuthorizer(
    callerTokens: ['api-gateway' => $gatewayToken],
    allowedMethodsByCaller: [
        'api-gateway' => ['SendMessage', 'SyncMessages', 'GetMessages'],
    ],
);
$grpc = new RoadRunnerMessageService($application, $logger, $authorizer);

(new PublicSendMessageRequest())->serializeToString();
(new SyncMessagesRequest())->serializeToString();
(new GetMessagesRequest())->serializeToString();

$server = new Server(new Invoker());
$server->registerService(MessageServiceInterface::class, $grpc);
$server->registerService(HealthServiceInterface::class, new RoadRunnerHealthService(authorizer: $authorizer));
$logger->info('MessageService started with RoadRunner gRPC worker');
$server->serve(LeaseMaintainingRelay::createWorker());
