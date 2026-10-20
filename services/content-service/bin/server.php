#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Contracts\Gen\Common\V1\HealthServiceInterface;
use Contracts\Gen\Content\V1\ContentServiceInterface;
use Packages\Database\EloquentConnection;
use Packages\Database\PostgresConnectionFactory;
use Packages\GrpcCommon\Health\RoadRunnerHealthService;
use Packages\GrpcCommon\InternalRpcAuthorizer;
use Packages\GrpcCommon\LeaseMaintainingRelay;
use Packages\Identity\AnonymousAuthorProjector;
use Packages\Identity\AnonymousAuthorSnapshotFactory;
use Packages\Identity\AnonymousReferenceFactory;
use Packages\Identity\UserServiceIdentityClient;
use Packages\IdGenerator\Snowflake;
use Packages\IdGenerator\WorkerId;
use Packages\Logging\JsonLogger;
use Packages\Observability\MetricsHttpServer;
use Packages\Outbox\Model\OutboxEvent;
use Packages\Outbox\OutboxRepository;
use Services\ContentService\Application\ContentApplicationService;
use Services\ContentService\Infrastructure\GrpcBlockStatusResolver;
use Services\ContentService\Transport\Grpc\ContentGrpcService;
use Spiral\RoadRunner\GRPC\Invoker;
use Spiral\RoadRunner\GRPC\Server;

MetricsHttpServer::startInBackground();

$logger = new JsonLogger('content-service', getenv('SERVICE_INSTANCE_ID') ?: 'content-01');
$pdo = null;

for ($attempt = 0; $attempt < 15; $attempt++) {
    try {
        $pdo = PostgresConnectionFactory::fromEnvironment();
        break;
    } catch (Throwable $e) {
        $logger->warning('Waiting for PostgreSQL...', ['exception' => $e::class]);
        sleep(2);
    }
}
if (!$pdo instanceof PDO) {
    $logger->error('Could not connect to PostgreSQL after retries');
    exit(1);
}

$workerId = WorkerId::resolve();
$snowflake = new Snowflake($workerId);
$outbox = new OutboxRepository($pdo, $snowflake);
EloquentConnection::warmup($pdo, OutboxEvent::class);

$identityKey = trim((string)(getenv('ANONYMOUS_IDENTITY_KEY') ?: ''));
if (strlen($identityKey) < 32) {
    throw new RuntimeException('ANONYMOUS_IDENTITY_KEY must be at least 32 bytes');
}
$references = new AnonymousReferenceFactory($identityKey);
$snapshotFactory = new AnonymousAuthorSnapshotFactory($references);
$projector = new AnonymousAuthorProjector($references);
$userDirectory = new UserServiceIdentityClient(
    target: getenv('GRPC_USER_SERVICE') ?: 'user-service:50052',
    serviceToken: trim((string)(getenv('GRPC_USER_TOKEN') ?: '')) ?: null,
    timeoutMs: (int)(getenv('GRPC_USER_TIMEOUT_MS') ?: 250),
);
$blockResolver = new GrpcBlockStatusResolver(
    target: getenv('GRPC_SOCIAL_SERVICE') ?: 'social-service:50054',
    serviceToken: trim((string)(getenv('GRPC_SOCIAL_SERVICE_TOKEN') ?: '')) ?: null,
    timeoutMs: (int)(getenv('GRPC_SOCIAL_BLOCK_TIMEOUT_MS') ?: 200),
);
$authorizer = new InternalRpcAuthorizer(
    callerTokens: [
        'api-gateway' => trim((string)(getenv('INTERNAL_RPC_GATEWAY_CONTENT_TOKEN') ?: '')),
        'chat-service' => trim((string)(getenv('INTERNAL_RPC_CHAT_CONTENT_TOKEN') ?: '')),
        'social-service' => trim((string)(getenv('INTERNAL_RPC_SOCIAL_CONTENT_TOKEN') ?: '')),
        'message-service' => trim((string)(getenv('INTERNAL_RPC_MESSAGE_CONTENT_TOKEN') ?: '')),
        'moderation-service' => trim((string)(getenv('INTERNAL_RPC_MODERATION_CONTENT_TOKEN') ?: '')),
    ],
    allowedMethodsByCaller: [
        'api-gateway' => [
            'CreatePost', 'GetPost', 'BatchGetPosts', 'DeletePost', 'CreateComment',
            'ListComments', 'DeleteComment', 'CanAccessResource',
        ],
        'chat-service' => ['ResolveAnonymousTarget', 'CanAccessResource'],
        'social-service' => ['CanAccessResource'],
        'message-service' => ['CanAccessResource'],
        'moderation-service' => ['UpdatePostStatus'],
    ],
);
$application = new ContentApplicationService(
    pdo: $pdo,
    ids: $snowflake,
    identities: $userDirectory,
    blocks: $blockResolver,
    snapshots: $snapshotFactory,
    projector: $projector,
    outbox: $outbox,
);
$server = new Server(new Invoker());
$server->registerService(ContentServiceInterface::class, new ContentGrpcService($application, $logger, $authorizer));
$server->registerService(HealthServiceInterface::class, new RoadRunnerHealthService(authorizer: $authorizer));

$logger->info('ContentService started with RoadRunner gRPC worker');
$server->serve(LeaseMaintainingRelay::createWorker());
