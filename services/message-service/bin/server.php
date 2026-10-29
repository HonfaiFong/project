<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Contracts\Gen\Common\V1\HealthServiceInterface;
use Contracts\Gen\Message\V1\GetMessagesRequest;
use Contracts\Gen\Message\V1\GetConversationLatestSequenceRequest;
use Contracts\Gen\Message\V1\MessageServiceInterface;
use Contracts\Gen\Message\V1\PublicSendMessageRequest;
use Contracts\Gen\Message\V1\SyncMessagesRequest;
use Packages\Database\PostgresConnectionFactory;
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
use Services\MessageService\Infrastructure\Postgres\PostgresMessageRepository;
use Services\MessageService\Infrastructure\Postgres\PostgresMessageTransactionManager;
use Services\MessageService\Infrastructure\Postgres\PostgresMessageConnection;
use Services\MessageService\Infrastructure\SnowflakeIdGenerator;
use Services\MessageService\Transport\Grpc\RoadRunnerMessageService;
use Spiral\RoadRunner\GRPC\Invoker;
use Spiral\RoadRunner\GRPC\Server;

MetricsHttpServer::startInBackground();

$logger = new JsonLogger('message-service', getenv('SERVICE_INSTANCE_ID') ?: 'message-01');
$pdo = null;
for ($attempt = 0; $attempt < 15; $attempt++) {
    try {
        $candidate = PostgresConnectionFactory::fromEnvironment(['PG_CONNECT_TIMEOUT_SECONDS' => '1', 'PG_STATEMENT_TIMEOUT_MS' => '300', 'PG_LOCK_TIMEOUT_MS' => '300']);
        $candidate->query('SELECT 1 FROM messages LIMIT 1');
        $pdo = $candidate;
        break;
    } catch (Throwable $e) {
        $logger->warning('Waiting for PostgreSQL', ['exception' => $e::class]);
        sleep(2);
    }
}
if (!$pdo instanceof PDO) {
    $logger->error('Could not connect to PostgreSQL after retries, exiting.');
    exit(1);
}

$anonymousKey = trim((string)getenv('ANONYMOUS_IDENTITY_KEY'));
if ($anonymousKey === '') {
    throw new RuntimeException('ANONYMOUS_IDENTITY_KEY is required');
}
$snowflake = new Snowflake(WorkerId::resolve());
$worker = new SnowflakeIdGenerator($snowflake);
$connection = new PostgresMessageConnection($pdo, static fn (): PDO => PostgresConnectionFactory::fromEnvironment([
    'PG_CONNECT_TIMEOUT_SECONDS' => '1', 'PG_STATEMENT_TIMEOUT_MS' => '300', 'PG_LOCK_TIMEOUT_MS' => '300',
]));
$application = new MessageApplicationService(
    repository: new PostgresMessageRepository($connection),
    transactions: new PostgresMessageTransactionManager($connection),
    chat: new GrpcChatMemberSnapshotClient(
        target: getenv('GRPC_CHAT_SERVICE') ?: 'chat-service:50055',
        serviceToken: trim((string)(getenv('GRPC_CHAT_SERVICE_TOKEN') ?: '')) ?: null,
        timeoutMs: (int)(getenv('GRPC_CHAT_MEMBER_TIMEOUT_MS') ?: 250),
    ),
    ids: $worker,
    projector: new AnonymousAuthorProjector(new AnonymousReferenceFactory($anonymousKey)),
);

$gatewayToken = trim((string)(getenv('INTERNAL_RPC_GATEWAY_MESSAGE_TOKEN') ?: ''));
$chatToken = trim((string)(getenv('INTERNAL_RPC_CHAT_MESSAGE_TOKEN') ?: ''));
$authorizer = new InternalRpcAuthorizer(
    callerTokens: ['api-gateway' => $gatewayToken, 'chat-service' => $chatToken],
    allowedMethodsByCaller: [
        'api-gateway' => ['SendMessage', 'SyncMessages', 'GetMessages'],
        'chat-service' => ['GetConversationLatestSequence'],
    ],
);
$grpc = new RoadRunnerMessageService($application, $logger, $authorizer);

(new PublicSendMessageRequest())->serializeToString();
(new SyncMessagesRequest())->serializeToString();
(new GetMessagesRequest())->serializeToString();
(new GetConversationLatestSequenceRequest())->serializeToString();

$server = new Server(new Invoker());
$server->registerService(MessageServiceInterface::class, $grpc);
$server->registerService(HealthServiceInterface::class, new RoadRunnerHealthService(
    readinessChecker: static function () use ($connection): bool {
        try {
            $connection->pdo()->query('SELECT 1 FROM messages LIMIT 1');
            return true;
        } catch (Throwable $exception) {
            $connection->discardIfBroken($exception);
            return false;
        }
    },
    authorizer: $authorizer,
));
$logger->info('MessageService started with RoadRunner gRPC worker');
$server->serve(LeaseMaintainingRelay::createWorker());
