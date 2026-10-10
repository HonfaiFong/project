<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use GuzzleHttp\Client;
use Packages\Database\EloquentConnection;
use Packages\GrpcCommon\Health\RoadRunnerHealthService;
use Packages\GrpcCommon\InternalRpcAuthorizer;
use Packages\GrpcCommon\DeadlineEnv;
use Packages\IdGenerator\Snowflake;
use Packages\IdGenerator\WorkerId;
use Packages\Logging\JsonLogger;
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
use Spiral\RoadRunner\Worker;

// Reject invalid deadlines before opening database or Redis connections.
$deadlineConfig = GrpcDeadlineConfig::fromEnvironment();
$socialHttpConnectTimeoutMs = DeadlineEnv::positiveMilliseconds('SOCIAL_HTTP_CONNECT_TIMEOUT_MS', 1000);
$socialHttpTimeoutMs = DeadlineEnv::positiveMilliseconds('SOCIAL_HTTP_TIMEOUT_MS', 3000);
if ($socialHttpConnectTimeoutMs > $socialHttpTimeoutMs) {
    throw new InvalidArgumentException('SOCIAL_HTTP_CONNECT_TIMEOUT_MS must not exceed SOCIAL_HTTP_TIMEOUT_MS');
}

$logger = new JsonLogger('auth-service', getenv('SERVICE_INSTANCE_ID') ?: 'auth-01');

// Database Connection
$mysqlHost = getenv('MYSQL_HOST') ?: '127.0.0.1';
$mysqlPort = getenv('MYSQL_PORT') ?: '3306';
$mysqlUser = getenv('MYSQL_USER') ?: 'root';
$mysqlPass = getenv('MYSQL_PASSWORD') ?: 'root_password';
$mysqlDb   = getenv('AUTH_DB_NAME') ?: 'auth_db';

// The two bootstrap retry loops below stay intentionally independent: each
// dependency keeps its own probe and logging, and cross-service de-duplication
// is outside this start-up script.
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

// WorkerId::resolve() reads the CSPRNG and may throw RandomException; the
// worker cannot start without a stable worker id, so the failure must abort
// startup instead of being swallowed.
/** @noinspection PhpUnhandledExceptionInspection */
$snowflake = new Snowflake(WorkerId::resolve());
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
// Strict parsing plus the cross-service relationship check: an invalid
// deadline or a directory budget that eats the gateway's whole auth deadline
// refuses startup instead of degrading into RPC_TIMEOUT traffic.
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
// Eloquent boots each model class lazily on first use; without this warmup the
// first RPC on a fresh worker pays ~250ms of boot cost and misses the gateway
// gRPC deadline.
EloquentConnection::warmup(
    $pdo,
    Device::class,
    RefreshToken::class,
    SocialIdentity::class,
    SocialIdentityAlias::class,
);
// The first protobuf encode initializes the shared descriptor pool (~80ms), the
// first phone normalization loads country metadata, and the first JWT encode
// loads the codec; pay all of it at worker start instead of on the first RPC.
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
$server->serve(Worker::create());
