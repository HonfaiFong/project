<?php

declare(strict_types=1);

use Apps\ApiGateway\Controller\AuthController;
use Apps\ApiGateway\Controller\ContentController;
use Apps\ApiGateway\Controller\SocialController;
use Apps\ApiGateway\Controller\ChatController;
use Apps\ApiGateway\Controller\MessageController;
use Apps\ApiGateway\Controller\MetricsController;
use Apps\ApiGateway\Controller\HealthController;
use Apps\ApiGateway\Controller\PersonaController;
use Apps\ApiGateway\Controller\UserController;
use Apps\ApiGateway\Middleware\AppEnvironmentMiddleware;
use Apps\ApiGateway\Middleware\AuthMiddleware;
use Apps\ApiGateway\Middleware\LogoutRateLimitMiddleware;
use Apps\ApiGateway\Middleware\MetricsAccessMiddleware;
use Apps\ApiGateway\Middleware\SocialBindRateLimitMiddleware;
use Apps\ApiGateway\Middleware\SocialBindUserRateLimitMiddleware;
use Apps\ApiGateway\Middleware\SocialLoginRateLimitMiddleware;
use Apps\ApiGateway\Middleware\TraceMiddleware;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;
use Webman\Route;

Route::disableDefaultRoute();

Route::get('/health', [HealthController::class, 'check']);
Route::get('/api/v1/health', [HealthController::class, 'check']);
Route::get('/metrics', [MetricsController::class, 'metrics'])
    ->middleware([MetricsAccessMiddleware::class]);
// 手机短信登录仅在 local、development 和 testing 环境可用。
Route::post('/api/v1/auth/login', [AuthController::class, 'login'])
    ->middleware([AppEnvironmentMiddleware::class]);
Route::post('/api/v1/auth/social/login', [AuthController::class, 'socialLogin'])
    ->middleware([SocialLoginRateLimitMiddleware::class]);
Route::post('/api/v1/auth/refresh', [AuthController::class, 'refresh']);
Route::post('/api/v1/auth/logout', [AuthController::class, 'logout'])
    ->middleware([LogoutRateLimitMiddleware::class, AuthMiddleware::class]);

Route::group('/api/v1', static function (): void {
    Route::post('/ws/ticket', [AuthController::class, 'createWsTicket']);
    Route::get('/me', [UserController::class, 'me']);
    Route::get('/personas', [PersonaController::class, 'list']);
    Route::get('/personas/{personaId:[0-9]+}', [PersonaController::class, 'get']);
    Route::patch('/personas/{personaId:[0-9]+}', [PersonaController::class, 'update']);
})->middleware([AuthMiddleware::class]);

// Content reads carry the verified actor too: private visibility and block
// checks belong to ContentService, so a public projection is never a bypass.
Route::get('/api/v1/posts/{postId:[0-9]+}', [ContentController::class, 'getPost'])
    ->middleware([AuthMiddleware::class]);
Route::get('/api/v1/posts/{postId:[0-9]+}/comments', [ContentController::class, 'listComments'])
    ->middleware([AuthMiddleware::class]);
Route::post('/api/v1/posts', [ContentController::class, 'createPost'])
    ->middleware([AuthMiddleware::class]);
Route::post('/api/v1/posts/{postId:[0-9]+}/comments', [ContentController::class, 'createComment'])
    ->middleware([AuthMiddleware::class]);
Route::delete('/api/v1/posts/{postId:[0-9]+}', [ContentController::class, 'deletePost'])
    ->middleware([AuthMiddleware::class]);
Route::delete('/api/v1/comments/{commentId:[0-9]+}', [ContentController::class, 'deleteComment'])
    ->middleware([AuthMiddleware::class]);

Route::post('/api/v1/posts/{postId:[0-9]+}/like', [SocialController::class, 'likePost'])->middleware([AuthMiddleware::class]);
Route::delete('/api/v1/posts/{postId:[0-9]+}/like', [SocialController::class, 'unlikePost'])->middleware([AuthMiddleware::class]);
Route::post('/api/v1/comments/{commentId:[0-9]+}/like', [SocialController::class, 'likeComment'])->middleware([AuthMiddleware::class]);
Route::delete('/api/v1/comments/{commentId:[0-9]+}/like', [SocialController::class, 'unlikeComment'])->middleware([AuthMiddleware::class]);
Route::post('/api/v1/personas/{personaId:[0-9]+}/follow', [SocialController::class, 'follow'])->middleware([AuthMiddleware::class]);
Route::delete('/api/v1/personas/{personaId:[0-9]+}/follow', [SocialController::class, 'unfollow'])->middleware([AuthMiddleware::class]);
Route::post('/api/v1/users/{userId:[0-9]+}/block', [SocialController::class, 'block'])->middleware([AuthMiddleware::class]);
Route::delete('/api/v1/users/{userId:[0-9]+}/block', [SocialController::class, 'unblock'])->middleware([AuthMiddleware::class]);

Route::get('/api/v1/conversations', [ChatController::class, 'list'])->middleware([AuthMiddleware::class]);
Route::post('/api/v1/conversations', [ChatController::class, 'create'])->middleware([AuthMiddleware::class]);
Route::get('/api/v1/conversations/{conversationId:[0-9]+}', [ChatController::class, 'get'])->middleware([AuthMiddleware::class]);
Route::get('/api/v1/users/{userId:[0-9]+}/can-message', [ChatController::class, 'checkCanMessage'])->middleware([AuthMiddleware::class]);
Route::post('/api/v1/conversations/{conversationId:[0-9]+}/read', [ChatController::class, 'markRead'])->middleware([AuthMiddleware::class]);

Route::post('/api/v1/conversations/{conversationId:[0-9]+}/messages', [MessageController::class, 'send'])->middleware([AuthMiddleware::class]);
Route::get('/api/v1/conversations/{conversationId:[0-9]+}/messages', [MessageController::class, 'list'])->middleware([AuthMiddleware::class]);
Route::get('/api/v1/conversations/{conversationId:[0-9]+}/messages/sync', [MessageController::class, 'sync'])->middleware([AuthMiddleware::class]);

// 在 VerifyToken 前拒绝滥用的 IP 或设备流量；认证后再单独统计用户，避免重复递增 IP 限流桶。
Route::post('/api/v1/auth/social/bind', [AuthController::class, 'socialBindIdentity'])
    ->middleware([SocialBindRateLimitMiddleware::class, AuthMiddleware::class, SocialBindUserRateLimitMiddleware::class]);

Route::fallback(static fn () => ApiResponse::error(
    ErrorCode::INVALID_ARGUMENT->value,
    'route not found',
    [],
    404
))->middleware([TraceMiddleware::class]);
