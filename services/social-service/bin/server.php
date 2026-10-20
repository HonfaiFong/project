<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Contracts\Gen\Common\V1\HealthServiceInterface;
use Contracts\Gen\Social\V1\BatchGetInteractionStateRequest;
use Contracts\Gen\Social\V1\BlockRequest;
use Contracts\Gen\Social\V1\CheckBidirectionalBlockRequest;
use Contracts\Gen\Social\V1\FollowRequest;
use Contracts\Gen\Social\V1\LikeRequest;
use Contracts\Gen\Social\V1\SocialServiceInterface;
use Contracts\Gen\Social\V1\UnblockRequest;
use Contracts\Gen\Social\V1\UnlikeRequest;
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
use Services\SocialService\Application\Service\SocialApplicationService;
use Services\SocialService\Infrastructure\Client\GrpcPersonaDirectory;
use Services\SocialService\Infrastructure\Client\GrpcResourceAccess;
use Services\SocialService\Infrastructure\Repository\PdoSocialRepository;
use Services\SocialService\Transport\Grpc\SocialGrpcService;
use Spiral\RoadRunner\GRPC\Invoker;
use Spiral\RoadRunner\GRPC\Server;

MetricsHttpServer::startInBackground();

$logger = new JsonLogger('social-service', getenv('SERVICE_INSTANCE_ID') ?: 'social-01');
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

$snowflake = new Snowflake(WorkerId::resolve());
$repository = new PdoSocialRepository($pdo);
$outbox = new OutboxRepository($pdo, $snowflake);
EloquentConnection::warmup($pdo, OutboxEvent::class);

$userTarget = getenv('GRPC_USER_SERVICE') ?: 'user-service:50052';
$userToken = trim((string)(getenv('GRPC_USER_SERVICE_TOKEN') ?: '')) ?: null;
$personas = new GrpcPersonaDirectory(
    target: $userTarget,
    timeoutMs: (int)(getenv('GRPC_USER_DIRECTORY_TIMEOUT_MS') ?: 150),
    serviceToken: $userToken,
);
$contentTarget = getenv('GRPC_CONTENT_SERVICE') ?: 'content-service:50053';
$contentServiceToken = trim((string)(getenv('GRPC_CONTENT_SERVICE_TOKEN') ?: '')) ?: null;
$resources = new GrpcResourceAccess(
    target: $contentTarget,
    timeoutMs: (int)(getenv('GRPC_CONTENT_AUTHORIZATION_TIMEOUT_MS') ?: 150),
    serviceToken: $contentServiceToken,
);

$gatewayToken = trim((string)(getenv('INTERNAL_RPC_GATEWAY_SOCIAL_TOKEN') ?: ''));
$chatToken = trim((string)(getenv('INTERNAL_RPC_CHAT_SOCIAL_TOKEN') ?: ''));
$contentToken = trim((string)(getenv('INTERNAL_RPC_CONTENT_SOCIAL_TOKEN') ?: ''));
$tokens = [];
$allowed = [];
if ($gatewayToken !== '') {
    $tokens['api-gateway'] = $gatewayToken;
    $allowed['api-gateway'] = [
        'Like', 'Unlike', 'Follow', 'Unfollow', 'Block', 'Unblock', 'BatchGetInteractionState',
    ];
}
if ($chatToken !== '') {
    $tokens['chat-service'] = $chatToken;
    $allowed['chat-service'] = ['CheckBidirectionalBlock'];
}
if ($contentToken !== '') {
    $tokens['content-service'] = $contentToken;
    $allowed['content-service'] = ['CheckBidirectionalBlock'];
}
if ($tokens === []) {
    throw new RuntimeException('At least one Social internal RPC caller token is required');
}
$authorizer = new InternalRpcAuthorizer($tokens, $allowed);

// Content ownership and visibility remain in ContentService. Social always
// delegates resource authorization and never opens content_db itself. A
// missing or invalid service token therefore fails closed at the RPC boundary.
$application = new SocialApplicationService(
    repository: $repository,
    personas: $personas,
    resources: $resources,
    snowflake: $snowflake,
    outbox: $outbox,
);
$grpc = new SocialGrpcService($application, $logger, $authorizer);

// Force descriptor construction during worker startup so schema/contract
// errors fail before the first request reaches the service.
(new LikeRequest())->serializeToString();
(new UnlikeRequest())->serializeToString();
(new FollowRequest())->serializeToString();
(new BlockRequest())->serializeToString();
(new UnblockRequest())->serializeToString();
(new BatchGetInteractionStateRequest())->serializeToString();
(new CheckBidirectionalBlockRequest())->serializeToString();

$server = new Server(new Invoker());
$server->registerService(SocialServiceInterface::class, $grpc);
$server->registerService(HealthServiceInterface::class, new RoadRunnerHealthService(authorizer: $authorizer));

$logger->info('SocialService started with RoadRunner gRPC worker');
$server->serve(LeaseMaintainingRelay::createWorker());
