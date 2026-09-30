<?php

declare(strict_types=1);

use Apps\ApiGateway\Controller\AuthController;
use Apps\ApiGateway\Controller\HealthController;
use Apps\ApiGateway\Controller\PersonaController;
use Apps\ApiGateway\Controller\UserController;
use Apps\ApiGateway\Middleware\AuthMiddleware;
use Apps\ApiGateway\Middleware\TraceMiddleware;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;
use Webman\Route;

Route::disableDefaultRoute();

Route::get('/health', [HealthController::class, 'check']);
Route::get('/api/v1/health', [HealthController::class, 'check']);
Route::post('/api/v1/auth/login', [AuthController::class, 'login']);
Route::post('/api/v1/auth/social/login', [AuthController::class, 'socialLogin']);
Route::post('/api/v1/auth/social/phone/code', [AuthController::class, 'sendSocialPhoneCode']);
Route::post('/api/v1/auth/social/bind-phone', [AuthController::class, 'bindSocialPhone']);
Route::post('/api/v1/auth/refresh', [AuthController::class, 'refresh']);

Route::group('/api/v1', static function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/ws/ticket', [AuthController::class, 'createWsTicket']);
    Route::get('/me', [UserController::class, 'me']);
    Route::get('/personas', [PersonaController::class, 'list']);
    Route::post('/personas', [PersonaController::class, 'create']);
    Route::get('/personas/{personaId:[0-9]+}', [PersonaController::class, 'get']);
    Route::patch('/personas/{personaId:[0-9]+}', [PersonaController::class, 'update']);
})->middleware([AuthMiddleware::class]);

Route::fallback(static fn () => ApiResponse::error(
    ErrorCode::INVALID_ARGUMENT->value,
    'route not found',
    [],
    404
))->middleware([TraceMiddleware::class]);
