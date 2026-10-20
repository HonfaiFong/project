<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Contracts\Gen\Common\V1\HealthServiceInterface;
use Contracts\Gen\Message\V1\GetMessagesRequest;
use Contracts\Gen\Message\V1\MessageServiceInterface;
use Contracts\Gen\Message\V1\PublicSendMessageRequest;
use Contracts\Gen\Message\V1\SyncMessagesRequest;
use Packages\Database\MongoConnectionFactory;
use Packages\GrpcCommon\Health\RoadRunnerHealthService;
use Packages\GrpcCommon\InternalRpcAuthorizer;
use Packages\GrpcCommon\LeaseMaintainingRelay;
use Packages\IdGenerator\Snowflake;
use Packages\IdGenerator\WorkerId;
use Packages\Identity\AnonymousAuthorProjector;
use Packages\Identity\AnonymousReferenceFactory;
use Packages\Logging\JsonLogger;
use Packages\Observability\MetricsHttpServer;
use Services\MessageService\Application\MessageApplicationService;
use Services\MessageService\Infrastructure\GrpcChatMemberSnapshotClient;
use Services\MessageService\Infrastructure\Mongo\MongoMessageRepository;
use Services\MessageService\Infrastructure\Mongo\MongoMessageTransactionManager;
use Services\MessageService\Infrastructure\SnowflakeIdGenerator;
use Services\MessageService\Transport\Grpc\RoadRunnerMessageService;
use Spiral\RoadRunner\GRPC\Invoker;
use Spiral\RoadRunner\GRPC\Server;

MetricsHttpServer::startInBackground();

$logger = new JsonLogger('message-service', getenv('SERVICE_INSTANCE_ID') ?: 'message-01');
$database = getenv('MONGODB_DATABASE') ?: 'message_db';

$mongo = null;
for ($attempt = 0; $attempt < 15; $attempt++) {
    try {
        $candidate = MongoConnectionFactory::forRpc();
        $candidate->selectDatabase($database)->command(['ping' => 1]);
        $mongo = $candidate;
        break;
    } catch (Throwable $e) {
        $logger->warning('Waiting for MongoDB primary', ['exception' => $e::class]);
        sleep(2);
    }
}
if (!$mongo instanceof MongoDB\Client) {
    $logger->error('Could not connect to MongoDB after retries, exiting.');
    exit(1);
}

$anonymousKey = trim((string)getenv('ANONYMOUS_IDENTITY_KEY'));
if ($anonymousKey === '') {
    throw new RuntimeException('ANONYMOUS_IDENTITY_KEY is required');
}
$snowflake = new Snowflake(WorkerId::resolve());
$worker = new SnowflakeIdGenerator($snowflake);
$application = new MessageApplicationService(
    repository: new MongoMessageRepository($mongo, $database),
    transactions: new MongoMessageTransactionManager($mongo, $database),
    chat: new GrpcChatMemberSnapshotClient(
        target: getenv('GRPC_CHAT_SERVICE') ?: 'chat-service:50055',
        serviceToken: trim((string)(getenv('GRPC_CHAT_SERVICE_TOKEN') ?: '')) ?: null,
        timeoutMs: (int)(getenv('GRPC_CHAT_MEMBER_TIMEOUT_MS') ?: 250),
    ),
    ids: $worker,
    projector: new AnonymousAuthorProjector(new AnonymousReferenceFactory($anonymousKey)),
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
$server->registerService(HealthServiceInterface::class, new RoadRunnerHealthService(
    readinessChecker: static function () use ($mongo, $database): bool {
        try {
            $mongo->selectDatabase($database)->command(['ping' => 1, 'maxTimeMS' => 100]);
            return true;
        } catch (Throwable) {
            return false;
        }
    },
    authorizer: $authorizer,
));
$logger->info('MessageService started with RoadRunner gRPC worker');
$server->serve(LeaseMaintainingRelay::createWorker());
