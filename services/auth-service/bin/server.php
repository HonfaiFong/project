<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use GuzzleHttp\Client;
use Packages\GrpcCommon\Health\RoadRunnerHealthService;
use Packages\IdGenerator\Snowflake;
use Packages\IdGenerator\WorkerId;
use Packages\Logging\JsonLogger;
use Packages\Phone\PhoneNormalizer;
use Contracts\Gen\Auth\V1\AuthServiceInterface;
use Contracts\Gen\Common\V1\HealthServiceInterface;
use Services\AuthService\Application\Service\AuthApplicationService;
use Services\AuthService\Infrastructure\Auth\LoginCredentialVerifier;
use Services\AuthService\Infrastructure\Cache\RedisDistributedLock;
use Services\AuthService\Infrastructure\Cache\RedisAccessTokenRevocationStore;
use Services\AuthService\Infrastructure\Cache\RedisSocialBindSessionStore;
use Services\AuthService\Infrastructure\Client\GrpcUserDirectoryClient;
use Services\AuthService\Infrastructure\OAuth\GuzzleOAuthHttpClient;
use Services\AuthService\Infrastructure\OAuth\QqOAuthProvider;
use Services\AuthService\Infrastructure\OAuth\SocialAuthProviderRegistry;
use Services\AuthService\Infrastructure\OAuth\WeChatOAuthProvider;
use Services\AuthService\Infrastructure\Phone\DevSmsSender;
use Services\AuthService\Infrastructure\Phone\RedisPhoneVerificationService;
use Services\AuthService\Infrastructure\Phone\UnavailableSmsSender;
use Services\AuthService\Infrastructure\Repository\PdoAuthRepository;
use Services\AuthService\Infrastructure\Repository\PdoSocialIdentityRepository;
use Services\AuthService\Infrastructure\Token\JwtTokenCodec;
use Services\AuthService\Infrastructure\Token\WsTicketIssuer;
use Services\AuthService\Transport\Grpc\RoadRunnerAuthService;
use Spiral\RoadRunner\GRPC\Invoker;
use Spiral\RoadRunner\GRPC\Server;
use Spiral\RoadRunner\Worker;

$logger = new JsonLogger('auth-service', getenv('SERVICE_INSTANCE_ID') ?: 'auth-01');

// Database Connection
$mysqlHost = getenv('MYSQL_HOST') ?: '127.0.0.1';
$mysqlPort = getenv('MYSQL_PORT') ?: '3306';
$mysqlUser = getenv('MYSQL_USER') ?: 'root';
$mysqlPass = getenv('MYSQL_PASSWORD') ?: 'root_password';
$mysqlDb   = getenv('AUTH_DB_NAME') ?: 'auth_db';

$maxRetries = 15;
$pdo = null;

for ($i = 0; $i < $maxRetries; $i++) {
    try {
        $dsn = "mysql:host={$mysqlHost};port={$mysqlPort};dbname={$mysqlDb};charset=utf8mb4";
        $pdo = new PDO($dsn, $mysqlUser, $mysqlPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $logger->info("Connected to MySQL successfully: {$mysqlDb}");
        break;
    } catch (\Throwable $e) {
        $logger->warning("Waiting for MySQL ({$mysqlDb})... " . $e->getMessage());
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
        $logger->info("Connected to Redis successfully: {$redisHost}:{$redisPort}");
        break;
    } catch (Throwable $e) {
        $logger->warning("Waiting for Redis ({$redisHost}:{$redisPort})... " . $e->getMessage());
        sleep(2);
    }
}

if (!$redis) {
    $logger->error("Could not connect to Redis after retries, exiting.");
    exit(1);
}

$snowflake = new Snowflake(WorkerId::resolve());
$authRepo = new PdoAuthRepository($pdo, $snowflake);
$jwtSecret = trim((string)getenv('JWT_SECRET'));
$wsSecret = trim((string)getenv('WS_TICKET_SECRET'));
if (strlen($jwtSecret) < 32 || strlen($wsSecret) < 32) {
    throw new RuntimeException('JWT_SECRET and WS_TICKET_SECRET must each be at least 32 bytes');
}
$accessTokenRevocationStore = new RedisAccessTokenRevocationStore($redis);
$credentialVerifier = LoginCredentialVerifier::fromEnvironment();
if (!$credentialVerifier->isDevelopment() && !$credentialVerifier->hasProvider()) {
    $logger->warning('AuthService running in production mode without configured Credential Provider. Real logins will fail until providers are registered.');
}
$userServiceTarget = getenv('GRPC_USER_SERVICE') ?: 'user-service:50052';
$userDirectory = new GrpcUserDirectoryClient($userServiceTarget);

$appEnvironment = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));
$isDevelopment = in_array($appEnvironment, ['local', 'development', 'dev', 'testing'], true);
$enableWeChat = filter_var(getenv('ENABLE_WECHAT_LOGIN') ?: 'false', FILTER_VALIDATE_BOOL);
$enableQq = filter_var(getenv('ENABLE_QQ_LOGIN') ?: 'false', FILTER_VALIDATE_BOOL);
$socialLoginEnabled = $enableWeChat || $enableQq;

$oauthHttpClient = new GuzzleOAuthHttpClient(
    new Client(),
    (int)(getenv('SOCIAL_HTTP_CONNECT_TIMEOUT_MS') ?: 1000),
    (int)(getenv('SOCIAL_HTTP_TIMEOUT_MS') ?: 3000),
);
$socialProviders = [];
if ($enableWeChat) {
    $socialProviders[] = new WeChatOAuthProvider(
        httpClient: $oauthHttpClient,
        appId: trim((string)getenv('WECHAT_APP_ID')),
        appSecret: trim((string)getenv('WECHAT_APP_SECRET')),
        identityNamespace: trim((string)(getenv('WECHAT_IDENTITY_NAMESPACE') ?: 'wechat-main')),
    );
}
if ($enableQq) {
    $socialProviders[] = new QqOAuthProvider(
        httpClient: $oauthHttpClient,
        appId: trim((string)getenv('QQ_APP_ID')),
        appKey: trim((string)getenv('QQ_APP_KEY')),
        redirectUri: trim((string)(getenv('QQ_REDIRECT_URI') ?: '')),
        identityNamespace: trim((string)(getenv('QQ_IDENTITY_NAMESPACE') ?: 'qq-main')),
    );
}

$smsSender = $isDevelopment ? new DevSmsSender() : new UnavailableSmsSender();
if ($socialLoginEnabled && !$isDevelopment) {
    throw new RuntimeException('Social login requires a configured production SMS adapter');
}
$smsPepper = trim((string)(getenv('SMS_CODE_PEPPER') ?: ''));
if ($socialLoginEnabled && $smsPepper === '') {
    throw new RuntimeException('SMS_CODE_PEPPER is required when social login is enabled');
}
if ($smsPepper === '') {
    $smsPepper = 'local-development-only-sms-pepper';
}
$phoneNormalizer = new PhoneNormalizer(trim((string)(getenv('PHONE_DEFAULT_REGION') ?: 'CN')));
$socialIdentityRepository = new PdoSocialIdentityRepository($pdo, $snowflake);
$socialBindSessionStore = new RedisSocialBindSessionStore($redis);
$distributedLock = new RedisDistributedLock($redis);
$phoneVerificationService = new RedisPhoneVerificationService(
    redis: $redis,
    smsSender: $smsSender,
    codePepper: $smsPepper,
    codeTtl: (int)(getenv('SMS_CODE_TTL') ?: 300),
    resendInterval: (int)(getenv('SMS_RESEND_INTERVAL') ?: 60),
    maxAttempts: (int)(getenv('SMS_VERIFY_MAX_ATTEMPTS') ?: 5),
    developmentCode: $isDevelopment ? trim((string)(getenv('DEV_SMS_CODE') ?: '')) : null,
);

$jwtCodec = new JwtTokenCodec($jwtSecret);
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
    socialBindSessionStore: $socialBindSessionStore,
    phoneVerificationService: $phoneVerificationService,
    distributedLock: $distributedLock,
    phoneNormalizer: $phoneNormalizer,
    socialBindTokenTtl: (int)(getenv('SOCIAL_BIND_TOKEN_TTL') ?: 600),
    smsCodeTtl: (int)(getenv('SMS_CODE_TTL') ?: 300),
    smsResendInterval: (int)(getenv('SMS_RESEND_INTERVAL') ?: 60),
);
$grpcService = new RoadRunnerAuthService($appService, $logger);
$server = new Server(new Invoker());
$server->registerService(AuthServiceInterface::class, $grpcService);
$server->registerService(HealthServiceInterface::class, new RoadRunnerHealthService());

$logger->info('AuthService started with RoadRunner gRPC worker');
$server->serve(Worker::create());
