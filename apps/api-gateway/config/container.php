<?php

declare(strict_types=1);

use Apps\ApiGateway\Action\CreateWsTicketAction;
use Apps\ApiGateway\Controller\AuthController;
use Apps\ApiGateway\Controller\HealthController;
use Apps\ApiGateway\Controller\PersonaController;
use Apps\ApiGateway\Controller\UserController;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Middleware\AuthMiddleware;
use Apps\ApiGateway\Middleware\SocialLoginRateLimitMiddleware;
use Psr\Container\ContainerInterface;

$socialLoginRateLimitCounter = null;
if (trim((string)(getenv('REDIS_HOST') ?: '')) !== '') {
    $redis = null;
    $socialLoginRateLimitCounter = static function (string $key, int $ttl) use (&$redis): int {
        if ($redis === null) {
            $redis = new Redis();
            $host = trim((string)getenv('REDIS_HOST'));
            $port = (int)(getenv('REDIS_PORT') ?: 6379);
            if (!$redis->connect($host, $port, 1.0)) {
                $redis = null;
                throw new RuntimeException('Gateway Redis connection failed');
            }
            $redis->setOption(Redis::OPT_READ_TIMEOUT, 1.0);

            $password = trim((string)(getenv('REDIS_PASSWORD') ?: ''));
            if ($password !== '' && !$redis->auth($password)) {
                $redis = null;
                throw new RuntimeException('Gateway Redis authentication failed');
            }
        }

        try {
            return (int)$redis->eval(<<<'LUA'
local current = redis.call('INCR', KEYS[1])
if current == 1 or redis.call('TTL', KEYS[1]) < 0 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end
return current
LUA, [$key, (string)$ttl], 1);
        } catch (\Throwable $e) {
            $redis = null;
            throw $e;
        }
    };
}

return (new Webman\Container())->addDefinitions([
    AuthServiceClient::class => static fn (): AuthServiceClient => new AuthServiceClient(
        target: getenv('GRPC_AUTH_SERVICE') ?: 'auth-service:50051',
        timeoutMs: (int)(getenv('GRPC_AUTH_TIMEOUT_MS') ?: 300),
        socialLoginTimeoutMs: (int)(getenv('GRPC_SOCIAL_LOGIN_TIMEOUT_MS') ?: 7000),
        socialPhoneCodeTimeoutMs: (int)(getenv('GRPC_SOCIAL_PHONE_CODE_TIMEOUT_MS') ?: 5000),
        socialBindTimeoutMs: (int)(getenv('GRPC_SOCIAL_BIND_TIMEOUT_MS') ?: 1000),
        socialHttpTimeoutMs: (int)(getenv('SOCIAL_HTTP_TIMEOUT_MS') ?: 3000),
    ),
    UserServiceClient::class => static fn (): UserServiceClient => new UserServiceClient(
        getenv('GRPC_USER_SERVICE') ?: 'user-service:50052'
    ),
    CreateWsTicketAction::class => static fn (ContainerInterface $c): CreateWsTicketAction => new CreateWsTicketAction(
        $c->get(AuthServiceClient::class), $c->get(UserServiceClient::class)
    ),
    AuthController::class => static fn (ContainerInterface $c): AuthController => new AuthController(
        $c->get(AuthServiceClient::class), $c->get(CreateWsTicketAction::class)
    ),
    UserController::class => static fn (ContainerInterface $c): UserController => new UserController(
        $c->get(UserServiceClient::class)
    ),
    PersonaController::class => static fn (ContainerInterface $c): PersonaController => new PersonaController(
        $c->get(UserServiceClient::class)
    ),
    HealthController::class => static fn (ContainerInterface $c): HealthController => new HealthController([
        'auth_service' => $c->get(AuthServiceClient::class),
        'user_service' => $c->get(UserServiceClient::class),
    ]),
    AuthMiddleware::class => static fn (ContainerInterface $c): AuthMiddleware => new AuthMiddleware(
        $c->get(AuthServiceClient::class)
    ),
    SocialLoginRateLimitMiddleware::class => static fn (): SocialLoginRateLimitMiddleware => new SocialLoginRateLimitMiddleware(
        counter: $socialLoginRateLimitCounter,
        limit: (int)(getenv('SOCIAL_LOGIN_RATE_LIMIT') ?: 30),
        windowSeconds: (int)(getenv('SOCIAL_LOGIN_RATE_WINDOW_SECONDS') ?: 60),
    ),
]);
