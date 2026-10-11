<?php

declare(strict_types=1);

use Apps\ApiGateway\Action\CreateWsTicketAction;
use Apps\ApiGateway\Action\PersonaLookup;
use Apps\ApiGateway\Action\WsTicketClient;
use Apps\ApiGateway\Controller\AuthController;
use Apps\ApiGateway\Controller\ContentController;
use Apps\ApiGateway\Controller\SocialController;
use Apps\ApiGateway\Controller\ChatController;
use Apps\ApiGateway\Controller\MessageController;
use Apps\ApiGateway\Controller\MetricsController;
use Apps\ApiGateway\Controller\HealthController;
use Apps\ApiGateway\Controller\PersonaController;
use Apps\ApiGateway\Controller\UserController;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\ContentServiceClient;
use Apps\ApiGateway\Grpc\SocialServiceClient;
use Apps\ApiGateway\Grpc\ChatServiceClient;
use Apps\ApiGateway\Grpc\MessageServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Http\TrustedProxyClientIpResolver;
use Apps\ApiGateway\Middleware\AuthMiddleware;
use Apps\ApiGateway\Middleware\AppEnvironmentMiddleware;
use Apps\ApiGateway\Middleware\SocialBindRateLimitMiddleware;
use Apps\ApiGateway\Middleware\SocialBindUserRateLimitMiddleware;
use Apps\ApiGateway\Middleware\SocialLoginRateLimitMiddleware;
use Packages\Observability\MetricsRegistry;
use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;

// Webman 配置键（apps/api-gateway/config/gateway.php），Laravel Idea 不识别该配置源，局部抑制误报。
/** @noinspection LaravelUnknownConfigKeyInspection */
$settings = config('gateway');
$socialLoginRateLimitCounter = null;
$trustedProxyCidrs = $settings['trusted_proxy_cidrs'];
$trustedProxyClientIpResolver = new TrustedProxyClientIpResolver($trustedProxyCidrs);
$redisSettings = $settings['redis'];
if ($redisSettings['host'] !== '') {
    $redis = null;
    $socialLoginRateLimitCounter = static function (string $key, int $ttl) use (&$redis, $redisSettings): int {
        if ($redis === null) {
            $redis = new Redis();
            if (!$redis->connect($redisSettings['host'], $redisSettings['port'], $redisSettings['connect_timeout'])) {
                $redis = null;
                MetricsRegistry::instance()->set('dependency_up', ['dependency' => 'redis'], 0);
                throw new RuntimeException('Gateway Redis connection failed');
            }
            $redis->setOption(Redis::OPT_READ_TIMEOUT, $redisSettings['read_timeout']);

            $password = $redisSettings['password'];
            if ($password !== '' && !$redis->auth($password)) {
                $redis = null;
                MetricsRegistry::instance()->set('dependency_up', ['dependency' => 'redis'], 0);
                throw new RuntimeException('Gateway Redis authentication failed');
            }
            MetricsRegistry::instance()->set('dependency_up', ['dependency' => 'redis'], 1);
        }

        try {
            $count = $redis->eval(<<<'LUA'
local current = redis.call('INCR', KEYS[1])
if current == 1 or redis.call('TTL', KEYS[1]) < 0 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end
return current
LUA, [$key, (string)$ttl], 1);
            if (!is_int($count)) {
                throw new RuntimeException('Gateway Redis rate counter failed');
            }
            MetricsRegistry::instance()->inc('redis_operations_total', ['operation' => 'rate_limit', 'status' => 'ok']);

            return $count;
        } catch (Throwable $e) {
            $redis = null;
            MetricsRegistry::instance()->set('dependency_up', ['dependency' => 'redis'], 0);
            MetricsRegistry::instance()->inc('redis_operations_total', ['operation' => 'rate_limit', 'status' => 'error']);
            throw $e;
        }
    };
}

$container = new Container();
$container->instance(TrustedProxyClientIpResolver::class, $trustedProxyClientIpResolver);
$container->singleton(Factory::class, static fn (Container $container): ValidationFactory => new ValidationFactory(
    new Translator(new ArrayLoader(), 'en'),
    $container,
));
$container->singleton(AuthServiceClient::class, static fn (): AuthServiceClient => new AuthServiceClient(
    target: $settings['grpc']['auth']['target'],
    timeoutMs: $settings['grpc']['auth']['timeout_ms'],
    socialLoginTimeoutMs: $settings['grpc']['auth']['social_login_timeout_ms'],
    socialBindTimeoutMs: $settings['grpc']['auth']['social_bind_timeout_ms'],
    socialHttpTimeoutMs: $settings['grpc']['auth']['social_http_timeout_ms'],
    serviceToken: $settings['grpc']['auth']['service_token'],
));
$container->singleton(UserServiceClient::class, static fn (): UserServiceClient => new UserServiceClient(
    target: $settings['grpc']['user']['target'],
    serviceToken: $settings['grpc']['user']['service_token'],
));
$container->singleton(ContentServiceClient::class, static fn (): ContentServiceClient => new ContentServiceClient(
    target: $settings['grpc']['content']['target'],
    timeoutMs: $settings['grpc']['content']['timeout_ms'],
    serviceToken: $settings['grpc']['content']['service_token'],
));
$container->singleton(SocialServiceClient::class, static fn (): SocialServiceClient => new SocialServiceClient(
    target: $settings['grpc']['social']['target'],
    timeoutMs: $settings['grpc']['social']['timeout_ms'],
    serviceToken: $settings['grpc']['social']['service_token'],
));
$container->singleton(ChatServiceClient::class, static fn (): ChatServiceClient => new ChatServiceClient(
    target: $settings['grpc']['chat']['target'],
    timeoutMs: $settings['grpc']['chat']['timeout_ms'],
    serviceToken: $settings['grpc']['chat']['service_token'],
));
$container->singleton(MessageServiceClient::class, static fn (): MessageServiceClient => new MessageServiceClient(
    target: $settings['grpc']['message']['target'],
    timeoutMs: $settings['grpc']['message']['timeout_ms'],
    serviceToken: $settings['grpc']['message']['service_token'],
));
$container->alias(AuthServiceClient::class, WsTicketClient::class);
$container->alias(UserServiceClient::class, PersonaLookup::class);

foreach ([CreateWsTicketAction::class, AuthController::class, UserController::class, PersonaController::class, ContentController::class, SocialController::class, ChatController::class, MessageController::class, MetricsController::class, AuthMiddleware::class] as $class) {
    $container->singleton($class);
}

$container->singleton(HealthController::class, static fn (Container $container): HealthController => new HealthController([
    'auth_service' => $container->get(AuthServiceClient::class),
    'user_service' => $container->get(UserServiceClient::class),
]));
$container->singleton(AppEnvironmentMiddleware::class, static fn (): AppEnvironmentMiddleware => new AppEnvironmentMiddleware(
    environment: $settings['environment'],
));
$container->singleton(SocialLoginRateLimitMiddleware::class, static fn (): SocialLoginRateLimitMiddleware => new SocialLoginRateLimitMiddleware(
    counter: $socialLoginRateLimitCounter,
    limit: $settings['social_login_rate_limit']['limit'],
    windowSeconds: $settings['social_login_rate_limit']['window_seconds'],
    trustedProxyCidrs: $trustedProxyCidrs,
    clientIpResolver: $trustedProxyClientIpResolver,
    environment: $settings['environment'],
    scenario: 'login',
    scenarioLimit: $settings['social_login_rate_limit']['scenario_limit'],
    deviceLimit: $settings['social_login_rate_limit']['device_limit'],
));
$container->singleton(SocialBindRateLimitMiddleware::class, static fn (): SocialBindRateLimitMiddleware => new SocialBindRateLimitMiddleware(
    counter: $socialLoginRateLimitCounter,
    limit: $settings['social_login_rate_limit']['limit'],
    windowSeconds: $settings['social_login_rate_limit']['window_seconds'],
    trustedProxyCidrs: $trustedProxyCidrs,
    clientIpResolver: $trustedProxyClientIpResolver,
    environment: $settings['environment'],
    scenarioLimit: $settings['social_login_rate_limit']['scenario_limit'],
    deviceLimit: $settings['social_login_rate_limit']['device_limit'],
    identityLimit: 0,
));
$container->singleton(SocialBindUserRateLimitMiddleware::class, static fn (): SocialBindUserRateLimitMiddleware => new SocialBindUserRateLimitMiddleware(
    counter: $socialLoginRateLimitCounter,
    identityLimit: $settings['social_bind_rate_limit']['user_limit'],
    identityWindowSeconds: $settings['social_bind_rate_limit']['user_window_seconds'],
    environment: $settings['environment'],
));

return $container;
