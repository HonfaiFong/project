<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Contracts\Gen\Chat\V1\ChatServiceInterface;
use Contracts\Gen\Chat\V1\GetConversationMemberSnapshotRequest;
use Contracts\Gen\Chat\V1\GetOrCreatePrivateConversationRequest;
use Contracts\Gen\Common\V1\HealthServiceInterface;
use Packages\GrpcCommon\Health\RoadRunnerHealthService;
use Packages\GrpcCommon\InternalRpcAuthorizer;
use Packages\GrpcCommon\LeaseMaintainingRelay;
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

[$application, $repository, $logger, $pdo] = require __DIR__ . '/bootstrap.php';

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
$server->serve(LeaseMaintainingRelay::createWorker());
