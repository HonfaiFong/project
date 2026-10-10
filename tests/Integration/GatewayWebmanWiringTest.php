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
use Apps\ApiGateway\Process\Http;
use Contracts\Errors\ErrorCode;
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

                return new GetPersonaResponse(['persona' => new PersonaProfile(['id' => '123', 'user_id' => '42'])]);
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

        // The phone-binding routes were replaced by the authenticated social bind route.
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

        [$status, , $params] = Route::dispatch('GET', '/api/v1/personas/123');
        $this->assertSame(Dispatcher::FOUND, $status);
        $this->assertSame('123', $params['personaId']);

        $request = new GatewayRequest("GET /missing HTTP/1.1\r\nHost: localhost\r\nX-Request-Id: missing-123\r\n\r\n");
        $response = Route::getFallback()($request);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('missing-123', json_decode($response->rawBody(), true)['request_id']);
    }

    /**
     * Webman includes config/ once per process at worker startup, so the values
     * (including the environment) are snapshots taken at load time.
     */
    #[RunInSeparateProcess]
    public function testGatewayConfigIsLoadedAtStartupAndEnvironmentIsSnapshotted(): void
    {
        $gatewayPath = dirname(__DIR__, 2) . '/apps/api-gateway';
        putenv('APP_ENV=testing');
        Config::load($gatewayPath . '/config', ['route']);
        putenv('APP_ENV=production');

        // Webman includes the config directory once at worker startup, so
        // request handling reads an in-memory array instead of loading files.
        $this->assertSame('development', Config::get('gateway.environment'));
        $this->assertSame('auth-service:50051', Config::get('gateway.grpc.auth.target'));
        $this->assertSame('user-service:50052', Config::get('gateway.grpc.user.target'));
        $this->assertSame(30, Config::get('gateway.social_login_rate_limit.limit'));
        $this->assertSame(60, Config::get('gateway.social_login_rate_limit.window_seconds'));

        // The pipeline value is the one captured when the config was loaded:
        // the later putenv('APP_ENV=production') must not change the decision.
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

        // Action dependencies resolve through their interfaces.
        $this->assertSame($container->get(AuthServiceClient::class), $container->get(WsTicketClient::class));
        $this->assertSame($container->get(UserServiceClient::class), $container->get(PersonaLookup::class));
        $this->assertSame($container->get(CreateWsTicketAction::class), $container->get(CreateWsTicketAction::class));
        $this->assertSame($container->get(AuthController::class), $container->get(AuthController::class));

        // Middleware singletons consume the settings loaded from config/gateway.php.
        $middleware = $container->get(AppEnvironmentMiddleware::class);
        $this->assertSame($middleware, $container->get(AppEnvironmentMiddleware::class));
        $request = new GatewayRequest("POST /api/v1/auth/login HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $response = $middleware->process($request, static fn (Request $request): Response => new Response(200));
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Phone (SMS) login is a development capability. Outside local/development/
     * dev/testing the route middleware has to reject the request before the
     * controller can call AuthService, without relying on any runtime flag.
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
        // 401 proves the environment middleware short-circuited the pipeline: a reachable
        // controller without valid credentials would answer 422 here.
        $this->assertSame(ErrorCode::AUTH_UNAUTHORIZED->httpStatus(), $response->getStatusCode());
    }
}
