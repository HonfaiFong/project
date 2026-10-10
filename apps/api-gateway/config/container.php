<?php

declare(strict_types=1);

use Apps\ApiGateway\Action\CreateWsTicketAction;
use Apps\ApiGateway\Action\PersonaLookup;
use Apps\ApiGateway\Action\WsTicketClient;
use Apps\ApiGateway\Controller\AuthController;
use Apps\ApiGateway\Controller\HealthController;
use Apps\ApiGateway\Controller\PersonaController;
use Apps\ApiGateway\Controller\UserController;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Http\TrustedProxyClientIpResolver;
use Apps\ApiGateway\Middleware\AuthMiddleware;
use Apps\ApiGateway\Middleware\AppEnvironmentMiddleware;
use Apps\ApiGateway\Middleware\SocialBindRateLimitMiddleware;
use Apps\ApiGateway\Middleware\SocialBindUserRateLimitMiddleware;
use Apps\ApiGateway\Middleware\SocialLoginRateLimitMiddleware;
use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;

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
                throw new RuntimeException('Gateway Redis connection failed');
            }
            $redis->setOption(Redis::OPT_READ_TIMEOUT, $redisSettings['read_timeout']);

            $password = $redisSettings['password'];
            if ($password !== '' && !$redis->auth($password)) {
                $redis = null;
                throw new RuntimeException('Gateway Redis authentication failed');
            }
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

            return $count;
        } catch (Throwable $e) {
            $redis = null;
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
$container->alias(AuthServiceClient::class, WsTicketClient::class);
$container->alias(UserServiceClient::class, PersonaLookup::class);

foreach ([CreateWsTicketAction::class, AuthController::class, UserController::class, PersonaController::class, AuthMiddleware::class] as $class) {
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
    environment: $settings['environment'],
    identityLimit: $settings['social_bind_rate_limit']['user_limit'],
    identityWindowSeconds: $settings['social_bind_rate_limit']['user_window_seconds'],
));

return $container;
