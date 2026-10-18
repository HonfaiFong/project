<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use GuzzleHttp\Client;
use Packages\Database\EloquentConnection;
use Packages\GrpcCommon\Health\RoadRunnerHealthService;
use Packages\GrpcCommon\InternalRpcAuthorizer;
use Packages\GrpcCommon\LeaseMaintainingRelay;
use Packages\GrpcCommon\DeadlineEnv;
use Packages\IdGenerator\Snowflake;
use Packages\IdGenerator\WorkerId;
use Packages\Logging\JsonLogger;
use Packages\Observability\MetricsHttpServer;
use Packages\Phone\PhoneNormalizer;
use Contracts\Gen\Auth\V1\AuthServiceInterface;
use Contracts\Gen\Auth\V1\BindSocialIdentityRequest;
use Contracts\Gen\Auth\V1\LoginRequest;
use Contracts\Gen\Auth\V1\SocialLoginRequest;
use Contracts\Gen\Auth\V1\VerifyTokenRequest;
use Contracts\Gen\Common\V1\HealthServiceInterface;
use Services\AuthService\Application\Service\AuthApplicationService;
use Services\AuthService\Infrastructure\Auth\LoginCredentialVerifier;
use Services\AuthService\Infrastructure\Config\GrpcDeadlineConfig;
use Services\AuthService\Infrastructure\Cache\RedisAccessTokenRevocationStore;
use Services\AuthService\Infrastructure\Client\GrpcUserDirectoryClient;
use Services\AuthService\Infrastructure\Model\Device;
use Services\AuthService\Infrastructure\Model\RefreshToken;
use Services\AuthService\Infrastructure\Model\SocialIdentity;
use Services\AuthService\Infrastructure\Model\SocialIdentityAlias;
use Services\AuthService\Infrastructure\OAuth\GuzzleOAuthHttpClient;
use Services\AuthService\Infrastructure\OAuth\QqOAuthProvider;
use Services\AuthService\Infrastructure\OAuth\SocialAuthProviderRegistry;
use Services\AuthService\Infrastructure\OAuth\WeChatOAuthProvider;
use Services\AuthService\Infrastructure\Repository\EloquentAuthRepository;
use Services\AuthService\Infrastructure\Repository\EloquentSocialIdentityRepository;
use Services\AuthService\Infrastructure\Token\JwtTokenCodec;
use Services\AuthService\Infrastructure\Token\WsTicketIssuer;
use Services\AuthService\Transport\Grpc\RoadRunnerAuthService;
use Spiral\RoadRunner\GRPC\Invoker;
use Spiral\RoadRunner\GRPC\Server;

// 建立数据库或 Redis 连接前，拒绝无效截止时间配置。
$deadlineConfig = GrpcDeadlineConfig::fromEnvironment();
$socialHttpConnectTimeoutMs = DeadlineEnv::positiveMilliseconds('SOCIAL_HTTP_CONNECT_TIMEOUT_MS', 1000);
$socialHttpTimeoutMs = DeadlineEnv::positiveMilliseconds('SOCIAL_HTTP_TIMEOUT_MS', 3000);
if ($socialHttpConnectTimeoutMs > $socialHttpTimeoutMs) {
    throw new InvalidArgumentException('SOCIAL_HTTP_CONNECT_TIMEOUT_MS must not exceed SOCIAL_HTTP_TIMEOUT_MS');
}

MetricsHttpServer::startInBackground();

$logger = new JsonLogger('auth-service', getenv('SERVICE_INSTANCE_ID') ?: 'auth-01');

// 数据库连接
$mysqlHost = getenv('MYSQL_HOST') ?: '127.0.0.1';
$mysqlPort = getenv('MYSQL_PORT') ?: '3306';
$mysqlUser = getenv('MYSQL_USER') ?: 'auth_runtime';
$mysqlPass = getenv('MYSQL_PASSWORD') ?: '';
$mysqlDb   = getenv('AUTH_DB_NAME') ?: 'auth_db';

// 下方两个启动重试循环有意保持独立，各依赖保留自身探测和日志；跨服务去重不在本启动脚本的范围内。
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

$redisHost = getenv('REDIS_HOST') ?: '127.0.0.1';
$redisPort = (int)(getenv('REDIS_PORT') ?: 6379);
$redisPassword = (string)(getenv('REDIS_PASSWORD') ?: '');
$redis = null;

for ($i = 0; $i < $maxRetries; $i++) {
    try {
        $candidate = new Redis();
        if (!$candidate->connect($redisHost, $redisPort, 1.0)) {
            throw new RuntimeException('Redis connection failed');
        }
        $candidate->setOption(Redis::OPT_READ_TIMEOUT, 1.0);
        if ($redisPassword !== '' && !$candidate->auth($redisPassword)) {
            throw new RuntimeException('Redis authentication failed');
        }
        $candidate->ping();
        $redis = $candidate;
        $logger->info("Connected to Redis successfully: $redisHost:$redisPort");
        break;
    } catch (Throwable $e) {
        $logger->warning("Waiting for Redis ($redisHost:$redisPort)... " . $e->getMessage());
        sleep(2);
    }
}

if (!$redis) {
    $logger->error("Could not connect to Redis after retries, exiting.");
    exit(1);
}

// WorkerId::resolve() 使用 Redis SET NX EX 租约（及 CSPRNG 所有权 token）；
// 工作进程不能在缺少稳定工作节点 ID 或 Redis 租约时启动，因此失败须终止启动，不能被吞掉。
/** @noinspection PhpUnhandledExceptionInspection */
$snowflake = new Snowflake(WorkerId::resolve($redis));
$authRepo = new EloquentAuthRepository($pdo, $snowflake);
$jwtSecret = trim((string)getenv('JWT_SECRET'));
$wsSecret = trim((string)getenv('WS_TICKET_SECRET'));
if (strlen($jwtSecret) < 32 || strlen($wsSecret) < 32) {
    throw new RuntimeException('JWT_SECRET and WS_TICKET_SECRET must each be at least 32 bytes');
}
$accessTokenRevocationStore = new RedisAccessTokenRevocationStore($redis);
$credentialVerifier = LoginCredentialVerifier::fromEnvironment();
if (!$credentialVerifier->isDevelopment() && !$credentialVerifier->hasProvider()) {
    $logger->info('Phone credential login is disabled in production; configured OAuth providers authenticate users.');
}
$userServiceTarget = getenv('GRPC_USER_SERVICE') ?: 'user-service:50052';
// 严格解析配置并校验跨服务预算关系；无效截止时间或占满网关认证总预算的用户目录调用预算须阻止启动，避免退化为大量
// RPC_TIMEOUT 请求。
$userDirectory = new GrpcUserDirectoryClient(
    target: $userServiceTarget,
    timeoutMs: $deadlineConfig->userDirectoryTimeoutMs,
    serviceToken: trim((string)(getenv('GRPC_USER_SERVICE_TOKEN') ?: '')) ?: null,
);
$internalRpcToken = trim((string)(getenv('INTERNAL_RPC_GATEWAY_AUTH_TOKEN') ?: ''));
$internalRpcAuthorizer = new InternalRpcAuthorizer(
    callerTokens: ['api-gateway' => $internalRpcToken],
);

$appEnvironment = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));
$isDevelopment = in_array($appEnvironment, ['local', 'development', 'dev', 'testing'], true);
$enableWeChat = filter_var(getenv('ENABLE_WECHAT_LOGIN') ?: 'false', FILTER_VALIDATE_BOOL);
$enableQq = filter_var(getenv('ENABLE_QQ_LOGIN') ?: 'false', FILTER_VALIDATE_BOOL);
$officialWeChatTokenEndpoint = 'https://api.weixin.qq.com/sns/oauth2/access_token';
$officialQqTokenEndpoint = 'https://graph.qq.com/oauth2.0/token';
$officialQqOpenIdEndpoint = 'https://graph.qq.com/oauth2.0/me';
$weChatTokenEndpoint = trim((string)(getenv('WECHAT_TOKEN_ENDPOINT') ?: ''));
$qqTokenEndpoint = trim((string)(getenv('QQ_TOKEN_ENDPOINT') ?: ''));
$qqOpenIdEndpoint = trim((string)(getenv('QQ_OPEN_ID_ENDPOINT') ?: ''));
$allowInsecureOAuthHttp = filter_var(
    getenv('SOCIAL_OAUTH_ALLOW_INSECURE_HTTP') ?: 'false',
    FILTER_VALIDATE_BOOL
);
if (!$isDevelopment && $allowInsecureOAuthHttp) {
    throw new RuntimeException('SOCIAL_OAUTH_ALLOW_INSECURE_HTTP is only allowed in local, development, or testing environments');
}
if (!$isDevelopment && (($weChatTokenEndpoint !== '' && $weChatTokenEndpoint !== $officialWeChatTokenEndpoint)
    || ($qqTokenEndpoint !== '' && $qqTokenEndpoint !== $officialQqTokenEndpoint)
    || ($qqOpenIdEndpoint !== '' && $qqOpenIdEndpoint !== $officialQqOpenIdEndpoint))) {
    throw new RuntimeException('OAuth endpoint overrides are only allowed in local, development, or testing environments');
}

$oauthHttpClient = new GuzzleOAuthHttpClient(
    new Client(),
    $socialHttpConnectTimeoutMs,
    $socialHttpTimeoutMs,
    allowInsecureHttp: $allowInsecureOAuthHttp,
);
$socialProviders = [];
if ($enableWeChat) {
    $socialProviders[] = new WeChatOAuthProvider(
        httpClient: $oauthHttpClient,
        appId: trim((string)getenv('WECHAT_APP_ID')),
        appSecret: trim((string)getenv('WECHAT_APP_SECRET')),
        identityNamespace: trim((string)(getenv('WECHAT_IDENTITY_NAMESPACE') ?: 'wechat-main')),
        tokenEndpoint: $weChatTokenEndpoint !== '' ? $weChatTokenEndpoint : $officialWeChatTokenEndpoint,
    );
}
if ($enableQq) {
    $socialProviders[] = new QqOAuthProvider(
        httpClient: $oauthHttpClient,
        appId: trim((string)getenv('QQ_APP_ID')),
        appKey: trim((string)getenv('QQ_APP_KEY')),
        redirectUri: trim((string)(getenv('QQ_REDIRECT_URI') ?: '')),
        identityNamespace: trim((string)(getenv('QQ_IDENTITY_NAMESPACE') ?: 'qq-main')),
        tokenEndpoint: $qqTokenEndpoint !== '' ? $qqTokenEndpoint : $officialQqTokenEndpoint,
        openIdEndpoint: $qqOpenIdEndpoint !== '' ? $qqOpenIdEndpoint : $officialQqOpenIdEndpoint,
    );
}

$phoneNormalizer = new PhoneNormalizer(trim((string)(getenv('PHONE_DEFAULT_REGION') ?: 'CN')));
$socialIdentityRepository = new EloquentSocialIdentityRepository($pdo, $snowflake);
// Eloquent 首次使用时才初始化各模型；若不预热，新工作进程的首个 RPC 需承担约 250 毫秒初始化开销，会超出网关 gRPC
// 截止时间。
EloquentConnection::warmup(
    $pdo,
    Device::class,
    RefreshToken::class,
    SocialIdentity::class,
    SocialIdentityAlias::class,
);
// 首次 Protobuf 编码会初始化共享描述符池（约 80 毫秒），首次手机号规范化会加载国家元数据，首次 JWT 编码会加载编
// 解码器；这些开销应在工作进程启动时承担。
(new LoginRequest())->serializeToString();
(new SocialLoginRequest())->serializeToString();
(new VerifyTokenRequest())->serializeToString();
(new BindSocialIdentityRequest())->serializeToString();
$phoneNormalizer->normalize('+8613800000000');

$jwtCodec = new JwtTokenCodec($jwtSecret);
$jwtCodec->decode($jwtCodec->encode(['sub' => 'warmup', 'exp' => time() + 60]));
$wsTicketIssuer = new WsTicketIssuer($wsSecret, 60);

$appService = new AuthApplicationService(
    authRepo: $authRepo,
    jwtCodec: $jwtCodec,
    wsTicketIssuer: $wsTicketIssuer,
    credentialVerifier: $credentialVerifier,
    userDirectory: $userDirectory,
    accessTokenRevocationStore: $accessTokenRevocationStore,
    socialProviderRegistry: new SocialAuthProviderRegistry($socialProviders),
    socialIdentityRepository: $socialIdentityRepository,
    phoneNormalizer: $phoneNormalizer,
    phoneLoginEnabled: $isDevelopment,
);
$grpcService = new RoadRunnerAuthService($appService, $logger, $internalRpcAuthorizer);
$server = new Server(new Invoker());
$server->registerService(AuthServiceInterface::class, $grpcService);
$server->registerService(
    HealthServiceInterface::class,
    new RoadRunnerHealthService(authorizer: $internalRpcAuthorizer),
);

$logger->info('AuthService started with RoadRunner gRPC worker');
$server->serve(LeaseMaintainingRelay::createWorker());
