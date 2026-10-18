<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apps\ApiGateway\Action\CreateWsTicketAction;
use Apps\ApiGateway\Action\PersonaLookup;
use Apps\ApiGateway\Action\WsTicketClient;
use Apps\ApiGateway\Controller\AuthController;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Http\GatewayRequest;
use Apps\ApiGateway\Middleware\AppEnvironmentMiddleware;
use Apps\ApiGateway\Middleware\SocialLoginRateLimitMiddleware;
use Apps\ApiGateway\Middleware\SocialBindRateLimitMiddleware;
use Apps\ApiGateway\Middleware\SocialBindUserRateLimitMiddleware;
use Apps\ApiGateway\Process\Http;
use Contracts\Errors\ErrorCode;
use Contracts\Gen\Auth\V1\BindSocialIdentityResponse;
use Contracts\Gen\Auth\V1\VerifyTokenRequest;
use Contracts\Gen\Auth\V1\VerifyTokenResponse;
use Contracts\Gen\User\V1\GetPersonaRequest;
use Contracts\Gen\User\V1\GetPersonaResponse;
use Contracts\Gen\User\V1\PersonaProfile;
use FastRoute\Dispatcher;
use Google\Protobuf\Internal\Message;
use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\Factory;
use Monolog\Logger;
use Packages\GrpcCommon\UnaryGrpcClient;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;
use Webman\Route;
use Webman\Config;
use Webman\Middleware;
use Webman\Http\Request;
use Webman\Http\Response;

final class GatewayWebmanWiringTest extends TestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    #[RunInSeparateProcess]
    public function testPersonaGetReceivesTheRouteIdThroughTheAuthenticatedWebmanPipeline(): void
    {
        putenv('APP_ENV=testing');
        $gatewayPath = dirname(__DIR__, 2) . '/apps/api-gateway';
        Config::load($gatewayPath . '/config', ['route']);
        Middleware::load(config('middleware'));
        Route::load([$gatewayPath . '/config']);

        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->exactly(2))->method('call')->willReturnCallback(
            static function (string $method, Message $request): Message {
                if ($method === '/auth.v1.AuthService/VerifyToken') {
                    self::assertInstanceOf(VerifyTokenRequest::class, $request);
                    self::assertSame('access-token', $request->getAccessToken());

                    return new VerifyTokenResponse(['valid' => true, 'user_id' => '42', 'device_id' => '7']);
                }

                self::assertSame('/user.v1.UserService/GetPersona', $method);
                self::assertInstanceOf(GetPersonaRequest::class, $request);
                self::assertSame('123', (string)$request->getPersonaId());

                return new GetPersonaResponse(['persona' => new PersonaProfile([
                    'id' => '123',
                    'user_id' => '42',
                    'type' => 1,
                    'status' => 1,
                    'identity_mode' => 'main',
                    'profile_accessible' => true,
                ])]);
            },
        );
        $container = config('container');
        $container->instance(AuthServiceClient::class, new AuthServiceClient(client: $grpc));
        $container->instance(UserServiceClient::class, new UserServiceClient(client: $grpc));
        new Http(GatewayRequest::class, new Logger('test'), $gatewayPath . '/app', $gatewayPath . '/public');

        [$status, $route, $parameters] = Route::dispatch('GET', '/api/v1/personas/123');
        $this->assertSame(Dispatcher::FOUND, $status);
        $request = new GatewayRequest("GET /api/v1/personas/123?personaId=999 HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer access-token\r\n\r\n");
        $response = Http::getCallback('', 'ApiGateway', $route['callback'], $parameters, true, $route['route'])($request);

        $this->assertSame(200, $response->getStatusCode());
        $persona = json_decode($response->rawBody(), true)['data'];
        $this->assertSame('123', $persona['id']);
        $this->assertArrayNotHasKey('user_id', $persona);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    #[RunInSeparateProcess]
    public function testPublicProtectedAndFallbackRoutesUseWebmanPipeline(): void
    {
        putenv('APP_ENV=testing');
        $gatewayPath = dirname(__DIR__, 2) . '/apps/api-gateway';
        Config::load($gatewayPath . '/config', ['route']);
        Middleware::load(config('middleware'));
        Route::load([$gatewayPath . '/config']);

        new Http(GatewayRequest::class, new Logger('test'), $gatewayPath . '/app', $gatewayPath . '/public');
        $this->assertTrue(Route::isDefaultRouteDisabled());

        [$status, $public] = Route::dispatch('POST', '/api/v1/auth/login');
        $this->assertSame(Dispatcher::FOUND, $status);
        $request = new GatewayRequest("POST /api/v1/auth/login HTTP/1.1\r\nHost: localhost\r\nX-Request-Id: public-123\r\n\r\n");
        $response = Http::getCallback('', 'ApiGateway', $public['callback'], [], true, $public['route'])($request);
        $this->assertSame(ErrorCode::INVALID_ARGUMENT->httpStatus(), $response->getStatusCode());
        $this->assertSame('public-123', json_decode($response->rawBody(), true)['request_id']);

        [$status, $social] = Route::dispatch('POST', '/api/v1/auth/social/login');
        $this->assertSame(Dispatcher::FOUND, $status);
        $socialRequest = new GatewayRequest("POST /api/v1/auth/social/login HTTP/1.1\r\nHost: localhost\r\nX-Request-Id: social-public-123\r\n\r\n");
        $socialResponse = Http::getCallback('', 'ApiGateway', $social['callback'], [], true, $social['route'])($socialRequest);
        $this->assertSame(ErrorCode::AUTH_SOCIAL_PROVIDER_UNSUPPORTED->httpStatus(), $socialResponse->getStatusCode());

        // 手机号绑定路由已由认证后的社交绑定路由替代。
        [$status] = Route::dispatch('POST', '/api/v1/auth/social/phone/code');
        $this->assertSame(Dispatcher::NOT_FOUND, $status);
        [$status] = Route::dispatch('POST', '/api/v1/auth/social/phone/bind');
        $this->assertSame(Dispatcher::NOT_FOUND, $status);

        [$status, $socialBind] = Route::dispatch('POST', '/api/v1/auth/social/bind');
        $this->assertSame(Dispatcher::FOUND, $status);
        $socialBindRequest = new GatewayRequest("POST /api/v1/auth/social/bind HTTP/1.1\r\nHost: localhost\r\nX-Request-Id: social-bind-public-123\r\n\r\n");
        $socialBindResponse = Http::getCallback('', 'ApiGateway', $socialBind['callback'], [], true, $socialBind['route'])($socialBindRequest);
        $this->assertSame(ErrorCode::AUTH_UNAUTHORIZED->httpStatus(), $socialBindResponse->getStatusCode());

        [$status, $protected] = Route::dispatch('GET', '/api/v1/me');
        $this->assertSame(Dispatcher::FOUND, $status);
        $request = new GatewayRequest("GET /api/v1/me HTTP/1.1\r\nHost: localhost\r\nX-Request-Id: protected-123\r\n\r\n");
        $response = Http::getCallback('', 'ApiGateway', $protected['callback'], [], true, $protected['route'])($request);
        $this->assertSame(ErrorCode::AUTH_UNAUTHORIZED->httpStatus(), $response->getStatusCode());
        $this->assertSame('protected-123', json_decode($response->rawBody(), true)['request_id']);

        foreach ([
            ['GET', '/api/v1/posts/123'],
            ['GET', '/api/v1/posts/123/comments'],
        ] as [$method, $path]) {
            [$status, $contentRoute] = Route::dispatch($method, $path);
            $this->assertSame(Dispatcher::FOUND, $status);
            $contentRequest = new GatewayRequest($method . ' ' . $path . " HTTP/1.1\r\nHost: localhost\r\n\r\n");
            $contentResponse = Http::getCallback('', 'ApiGateway', $contentRoute['callback'], [], true, $contentRoute['route'])($contentRequest);
            $this->assertSame(ErrorCode::AUTH_UNAUTHORIZED->httpStatus(), $contentResponse->getStatusCode());
        }

        [$status, , $params] = Route::dispatch('GET', '/api/v1/personas/123');
        $this->assertSame(Dispatcher::FOUND, $status);
        $this->assertSame('123', $params['personaId']);

        $request = new GatewayRequest("GET /missing HTTP/1.1\r\nHost: localhost\r\nX-Request-Id: missing-123\r\n\r\n");
        $response = Route::getFallback()($request);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('missing-123', json_decode($response->rawBody(), true)['request_id']);
    }

    #[RunInSeparateProcess]
    public function testLogoutRateLimitRunsBeforeInvalidBearerRecovery(): void
    {
        putenv('APP_ENV=testing');
        putenv('REDIS_HOST');
        putenv('AUTH_LOGOUT_RATE_LIMIT=1');
        $gatewayPath = dirname(__DIR__, 2) . '/apps/api-gateway';
        Config::load($gatewayPath . '/config', ['route']);
        Middleware::load(config('middleware'));
        Route::load([$gatewayPath . '/config']);
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->exactly(2))->method('call')->willReturnCallback(static function (string $method): Message {
            if ($method === '/auth.v1.AuthService/VerifyToken') {
                return new VerifyTokenResponse(['valid' => false]);
            }
            self::assertSame('/auth.v1.AuthService/Logout', $method);
            return new \Contracts\Gen\Auth\V1\LogoutResponse(['success' => true]);
        });
        $container = config('container');
        $container->instance(AuthServiceClient::class, new AuthServiceClient(client: $grpc));
        $keys = [];
        $counter = static function (string $key) use (&$keys): int {
            return $keys[$key] = ($keys[$key] ?? 0) + 1;
        };
        (new \ReflectionProperty(SocialLoginRateLimitMiddleware::class, 'counter'))->setValue(
            $container->get(\Apps\ApiGateway\Middleware\LogoutRateLimitMiddleware::class), $counter);
        new Http(GatewayRequest::class, new Logger('test'), $gatewayPath . '/app', $gatewayPath . '/public');
        [, $route] = Route::dispatch('POST', '/api/v1/auth/logout');
        $callback = Http::getCallback('', 'ApiGateway', $route['callback'], [], true, $route['route']);
        foreach ([200, 429] as $expectedStatus) {
            $request = new GatewayRequest("POST /api/v1/auth/logout HTTP/1.1\r\nHost: localhost\r\n"
                . "Authorization: Bearer revoked-token\r\nContent-Type: application/json\r\n\r\n"
                . json_encode(['refresh_token' => 'refresh']));
            $this->assertSame($expectedStatus, $callback($request)->getStatusCode());
        }
        $this->assertMatchesRegularExpression('/auth_logout_recovery_total\{[^}\n]*status="200"[^}\n]*\} 1/',
            \Packages\Observability\MetricsRegistry::instance()->render(false));
    }

    #[RunInSeparateProcess]
    public function testBindRateLimitsUseTheConfiguredContainerAndWebmanPipeline(): void
    {
        putenv('APP_ENV=testing');
        putenv('REDIS_HOST');
        putenv('SOCIAL_LOGIN_RATE_LIMIT=2');
        putenv('SOCIAL_LOGIN_RATE_SCENARIO_LIMIT=100');
        putenv('SOCIAL_LOGIN_RATE_DEVICE_LIMIT=100');
        putenv('SOCIAL_BIND_USER_RATE_LIMIT=1');
        $gatewayPath = dirname(__DIR__, 2) . '/apps/api-gateway';
        Config::load($gatewayPath . '/config', ['route']);
        Middleware::load(config('middleware'));
        Route::load([$gatewayPath . '/config']);
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->exactly(3))->method('call')->willReturnCallback(
            static function (string $method): Message {
                if ($method === '/auth.v1.AuthService/VerifyToken') {
                    return new VerifyTokenResponse(['valid' => true, 'user_id' => '42', 'device_id' => '7']);
                }
                self::assertSame('/auth.v1.AuthService/BindSocialIdentity', $method);
                return new BindSocialIdentityResponse(['user_id' => '42', 'provider' => 1]);
            },
        );
        $container = config('container');
        $container->instance(AuthServiceClient::class, new AuthServiceClient(client: $grpc));
        $keys = [];
        $counter = static function (string $key) use (&$keys): int {
            return $keys[$key] = ($keys[$key] ?? 0) + 1;
        };
        // 仅替换外部计数器，保留真实容器中的限额和窗口配置绑定。
        $counterProperty = new \ReflectionProperty(SocialLoginRateLimitMiddleware::class, 'counter');
        foreach ([SocialBindRateLimitMiddleware::class, SocialBindUserRateLimitMiddleware::class] as $class) {
            $counterProperty->setValue($container->get($class), $counter);
        }
        new Http(GatewayRequest::class, new Logger('test'), $gatewayPath . '/app', $gatewayPath . '/public');
        [, $route] = Route::dispatch('POST', '/api/v1/auth/social/bind');
        $callback = Http::getCallback('', 'ApiGateway', $route['callback'], [], true, $route['route']);
        foreach ([200, 429, 429, 429] as $expectedStatus) {
            $request = new GatewayRequest("POST /api/v1/auth/social/bind HTTP/1.1\r\nHost: localhost\r\n"
                . "Authorization: Bearer access-token\r\nContent-Type: application/json\r\n\r\n"
                . '{"provider":"wechat","code":"code","device":{"device_uuid":"device-1"},"user_id":"999"}');
            $response = $callback($request);
            $this->assertSame($expectedStatus, $response->getStatusCode());
        }
        $this->assertSame(4, $keys['auth:gateway:social-login:' . hash('sha256', '0.0.0.0')]);
        $this->assertSame(2, $keys['auth:gateway:social-login:bind:' . hash('sha256', '0.0.0.0')]);
        $this->assertSame(2, $keys['auth:gateway:social-login:device:' . hash('sha256', '0.0.0.0|device-1')]);
        $this->assertSame(2, $keys['auth:gateway:social-login:user:bind:' . hash('sha256', '42')]);
    }

    /**
     * Webman 在工作进程启动时为每个进程加载 config/ 一次，因此配置值（包括环境）是加载时的快照。
     */
    #[RunInSeparateProcess]
    public function testGatewayConfigIsLoadedAtStartupAndEnvironmentIsSnapshotted(): void
    {
        $gatewayPath = dirname(__DIR__, 2) . '/apps/api-gateway';
        putenv('APP_ENV=testing');
        Config::load($gatewayPath . '/config', ['route']);
        putenv('APP_ENV=production');

        // Webman 在工作进程启动时仅加载一次配置目录，请求处理读取内存数组，无须加载文件。
        $this->assertSame('development', Config::get('gateway.environment'));
        $this->assertSame('auth-service:50051', Config::get('gateway.grpc.auth.target'));
        $this->assertSame('user-service:50052', Config::get('gateway.grpc.user.target'));
        $this->assertSame(60, Config::get('gateway.social_login_rate_limit.limit'));
        $this->assertSame(60, Config::get('gateway.social_login_rate_limit.window_seconds'));

        // 处理管线使用配置加载时捕获的值；后续 putenv('APP_ENV=production') 不能改变决定。
        $middleware = new AppEnvironmentMiddleware((string) Config::get('gateway.environment'));
        $request = new GatewayRequest("POST /api/v1/auth/login HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $response = $middleware->process($request, static fn (Request $request): Response => new Response(200));
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    #[RunInSeparateProcess]
    public function testGatewayContainerIsAnIlluminateContainerWithSingletonControllerAndActionBindings(): void
    {
        putenv('APP_ENV=testing');
        $gatewayPath = dirname(__DIR__, 2) . '/apps/api-gateway';
        Config::load($gatewayPath . '/config', ['route']);

        $container = config('container');
        $this->assertInstanceOf(Container::class, $container);
        $this->assertInstanceOf(Factory::class, $container->get(Factory::class));
        $this->assertSame($container->get(Factory::class), $container->get(Factory::class));

        // 动作依赖通过各自接口解析。
        $this->assertSame($container->get(AuthServiceClient::class), $container->get(WsTicketClient::class));
        $this->assertSame($container->get(UserServiceClient::class), $container->get(PersonaLookup::class));
        $this->assertSame($container->get(CreateWsTicketAction::class), $container->get(CreateWsTicketAction::class));
        $this->assertSame($container->get(AuthController::class), $container->get(AuthController::class));

        // 中间件单例使用 config/gateway.php 加载的配置。
        $middleware = $container->get(AppEnvironmentMiddleware::class);
        $this->assertSame($middleware, $container->get(AppEnvironmentMiddleware::class));
        $request = new GatewayRequest("POST /api/v1/auth/login HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $response = $middleware->process($request, static fn (Request $request): Response => new Response(200));
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * 手机短信登录仅用于开发；在 local/development/dev/testing 之外，路由中间件须在控制器调用 AuthService 前拒绝请求，
     * 且不能依赖任何运行时标志。
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    #[RunInSeparateProcess]
    public function testPhoneLoginRouteIsRejectedOutsideDevelopmentEnvironments(): void
    {
        putenv('APP_ENV=production');
        $gatewayPath = dirname(__DIR__, 2) . '/apps/api-gateway';
        Config::load($gatewayPath . '/config', ['route']);
        Middleware::load(config('middleware'));
        Route::load([$gatewayPath . '/config']);

        new Http(GatewayRequest::class, new Logger('test'), $gatewayPath . '/app', $gatewayPath . '/public');

        [$status, $route] = Route::dispatch('POST', '/api/v1/auth/login');
        $this->assertSame(Dispatcher::FOUND, $status);
        $request = new GatewayRequest("POST /api/v1/auth/login HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $response = Http::getCallback('', 'ApiGateway', $route['callback'], [], true, $route['route'])($request);
        // 401 证明环境中间件已提前结束处理管线；若控制器能被调用，此处无效凭据应返回 422。
        $this->assertSame(ErrorCode::AUTH_UNAUTHORIZED->httpStatus(), $response->getStatusCode());
    }
}
