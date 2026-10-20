<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Contracts\Gen\Chat\V1\ChatServiceInterface;
use Contracts\Gen\Chat\V1\GetConversationMemberSnapshotRequest;
use Contracts\Gen\Chat\V1\GetOrCreatePrivateConversationRequest;
use Contracts\Gen\Common\V1\HealthServiceInterface;
use Packages\Database\PostgresConnectionFactory;
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
$pdo = null;
for ($attempt = 0; $attempt < 15; $attempt++) {
    try {
        $pdo = PostgresConnectionFactory::fromEnvironment();
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

return [$application, $repository, $logger, $pdo];
