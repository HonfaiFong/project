<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Http;

use Apps\ApiGateway\Controller\AuthController;
use Apps\ApiGateway\Controller\HealthController;
use Apps\ApiGateway\Controller\PersonaController;
use Apps\ApiGateway\Controller\UserController;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Middleware\AuthMiddleware;
use Packages\Logging\JsonLogger;
use Webman\Http\Request;
use Webman\Route;

final class RouteRegistrar
{
    public static function register(): void
    {
        $logger = new JsonLogger('api-gateway', getenv('SERVICE_INSTANCE_ID') ?: 'gw-01');
        $authClient = new AuthServiceClient(getenv('GRPC_AUTH_SERVICE') ?: 'auth-service:50051');
        $userClient = new UserServiceClient(getenv('GRPC_USER_SERVICE') ?: 'user-service:50052');
        $authController = new AuthController($authClient, $userClient);
        $userController = new UserController($userClient);
        $personaController = new PersonaController($userClient);
        $healthController = new HealthController($authClient, $userClient);
        $authMiddleware = new AuthMiddleware($authClient);
        $gateway = new WebmanGateway($logger);

        Route::get('/health', static fn (Request $request) => $gateway->handle(
            $request,
            static fn (): array => $healthController->check()
        ));
        Route::get('/api/v1/health', static fn (Request $request) => $gateway->handle(
            $request,
            static fn (): array => $healthController->check()
        ));

        Route::post('/api/v1/auth/login', static fn (Request $request) => $gateway->handle(
            $request,
            static fn (array $body, array $headers): array => $authController->login($body, $headers)
        ));
        Route::post('/api/v1/auth/refresh', static fn (Request $request) => $gateway->handle(
            $request,
            static fn (array $body): array => $authController->refresh($body)
        ));
        Route::post('/api/v1/auth/logout', static fn (Request $request) => $gateway->handle(
            $request,
            static function (array $body, array $headers) use ($authController, $authMiddleware): array {
                $authMiddleware->authenticate($headers);
                return $authController->logout($body, $headers);
            }
        ));
        Route::post('/api/v1/ws/ticket', static fn (Request $request) => $gateway->handle(
            $request,
            static function (array $body, array $headers) use ($authController, $authMiddleware): array {
                $auth = $authMiddleware->authenticate($headers);
                return $authController->createWsTicket($body, $auth);
            }
        ));

        Route::get('/api/v1/me', static fn (Request $request) => $gateway->handle(
            $request,
            static function (array $body, array $headers) use ($userController, $authMiddleware): array {
                return $userController->me($authMiddleware->authenticate($headers));
            }
        ));
        Route::get('/api/v1/personas', static fn (Request $request) => $gateway->handle(
            $request,
            static function (array $body, array $headers) use ($personaController, $authMiddleware): array {
                return $personaController->list($authMiddleware->authenticate($headers));
            }
        ));
        Route::post('/api/v1/personas', static fn (Request $request) => $gateway->handle(
            $request,
            static function (array $body, array $headers) use ($personaController, $authMiddleware): array {
                return $personaController->create($body, $authMiddleware->authenticate($headers));
            }
        ));
        Route::get('/api/v1/personas/{personaId:[0-9]+}', static function (Request $request, string $personaId) use ($gateway, $personaController, $authMiddleware): mixed {
            return $gateway->handle(
            $request,
            static function (array $body, array $headers) use ($personaController, $authMiddleware, $personaId): array {
                $authMiddleware->authenticate($headers);
                return $personaController->get($personaId);
            }
        );
        });
        Route::patch('/api/v1/personas/{personaId:[0-9]+}', static function (Request $request, string $personaId) use ($gateway, $personaController, $authMiddleware): mixed {
            return $gateway->handle(
            $request,
            static function (array $body, array $headers) use ($personaController, $authMiddleware, $personaId): array {
                return $personaController->update(
                    $personaId,
                    $body,
                    $authMiddleware->authenticate($headers)
                );
            }
        );
        });

        Route::fallback(static function (Request $request, int $status = 404) use ($gateway): mixed {
            return $gateway->notFound($request);
        });
    }
}
