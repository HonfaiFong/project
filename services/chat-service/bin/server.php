<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Contracts\Gen\Chat\V1\ChatServiceInterface;
use Contracts\Gen\Chat\V1\GetConversationMemberSnapshotRequest;
use Contracts\Gen\Chat\V1\GetOrCreatePrivateConversationRequest;
use Contracts\Gen\Common\V1\HealthServiceInterface;
use Packages\GrpcCommon\Health\RoadRunnerHealthService;
use Packages\GrpcCommon\InternalRpcAuthorizer;
use Packages\IdGenerator\Snowflake;
use Packages\IdGenerator\WorkerId;
use Packages\Identity\AnonymousReferenceFactory;
use Packages\Identity\UserServiceIdentityClient;
use Packages\Logging\JsonLogger;
use Packages\Observability\MetricsHttpServer;
use Services\ChatService\Application\ChatApplicationService;
use Services\ChatService\Infrastructure\GrpcBlockStatusResolver;
use Services\ChatService\Infrastructure\GrpcContentTargetResolver;
use Services\ChatService\Infrastructure\PdoChatRepository;
use Services\ChatService\Infrastructure\SnowflakeIdGenerator;
use Services\ChatService\Infrastructure\UserIdentityDirectoryAdapter;
use Services\ChatService\Transport\Grpc\RoadRunnerChatService;
use Spiral\RoadRunner\GRPC\Invoker;
use Spiral\RoadRunner\GRPC\Server;
use Spiral\RoadRunner\Worker;

MetricsHttpServer::startInBackground();

$logger = new JsonLogger('chat-service', getenv('SERVICE_INSTANCE_ID') ?: 'chat-01');
$mysqlHost = getenv('MYSQL_HOST') ?: '127.0.0.1';
$mysqlPort = getenv('MYSQL_PORT') ?: '3306';
$mysqlUser = getenv('MYSQL_USER') ?: 'chat_runtime';
$mysqlPass = getenv('MYSQL_PASSWORD') ?: '';
$mysqlDb = getenv('CHAT_DB_NAME') ?: 'chat_db';

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

$worker = new SnowflakeIdGenerator(new Snowflake(WorkerId::resolve()));
$repository = new PdoChatRepository($pdo);
$userDirectory = new UserServiceIdentityClient(
    target: getenv('GRPC_USER_SERVICE') ?: 'user-service:50052',
    serviceToken: trim((string)(getenv('GRPC_USER_SERVICE_TOKEN') ?: '')) ?: null,
    timeoutMs: (int)(getenv('GRPC_USER_DIRECTORY_TIMEOUT_MS') ?: 250),
);
$contentResolver = new GrpcContentTargetResolver(
    target: getenv('GRPC_CONTENT_SERVICE') ?: 'content-service:50053',
    serviceToken: trim((string)(getenv('GRPC_CONTENT_SERVICE_TOKEN') ?: '')) ?: null,
    timeoutMs: (int)(getenv('GRPC_CONTENT_AUTHORIZATION_TIMEOUT_MS') ?: 250),
);
$blockResolver = new GrpcBlockStatusResolver(
    target: getenv('GRPC_SOCIAL_SERVICE') ?: 'social-service:50054',
    serviceToken: trim((string)(getenv('GRPC_SOCIAL_SERVICE_TOKEN') ?: '')) ?: null,
    timeoutMs: (int)(getenv('GRPC_SOCIAL_BLOCK_TIMEOUT_MS') ?: 200),
);
$anonymousKey = trim((string)getenv('ANONYMOUS_IDENTITY_KEY'));
if ($anonymousKey === '') {
    throw new RuntimeException('ANONYMOUS_IDENTITY_KEY is required');
}

$application = new ChatApplicationService(
    repository: $repository,
    users: new UserIdentityDirectoryAdapter($userDirectory),
    targets: $contentResolver,
    blocks: $blockResolver,
    references: new AnonymousReferenceFactory($anonymousKey),
    ids: $worker,
);

$gatewayToken = trim((string)(getenv('INTERNAL_RPC_GATEWAY_CHAT_TOKEN') ?: ''));
$messageToken = trim((string)(getenv('INTERNAL_RPC_MESSAGE_CHAT_TOKEN') ?: ''));
$authorizer = new InternalRpcAuthorizer(
    callerTokens: [
        'api-gateway' => $gatewayToken,
        'message-service' => $messageToken,
    ],
    allowedMethodsByCaller: [
        'api-gateway' => [
            'GetOrCreatePrivateConversation',
            'GetConversation',
            'ListConversations',
            'CheckCanMessage',
            'MarkRead',
        ],
        'message-service' => ['GetConversationMemberSnapshot'],
    ],
);
$grpc = new RoadRunnerChatService($application, $logger, $authorizer);

// Force descriptor construction at startup so contract errors fail before
// accepting traffic. The body user/persona fields remain non-authoritative.
(new GetOrCreatePrivateConversationRequest())->serializeToString();
(new GetConversationMemberSnapshotRequest())->serializeToString();

$server = new Server(new Invoker());
$server->registerService(ChatServiceInterface::class, $grpc);
$server->registerService(HealthServiceInterface::class, new RoadRunnerHealthService(authorizer: $authorizer));
$logger->info('ChatService started with RoadRunner gRPC worker');
$server->serve(Worker::create());
