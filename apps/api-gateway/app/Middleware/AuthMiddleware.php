<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Middleware;

use Apps\ApiGateway\Http\GatewayRequest;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Contracts\Errors\ErrorCode;
use LogicException;
use Packages\Tracing\TraceContext;
use Exception;
use RuntimeException;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final readonly class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthServiceClient $authClient)
    {
    }

    /**
     * @throws Exception
     */
    public function process(Request $request, callable $handler): Response
    {
        if (!$request instanceof GatewayRequest) {
            throw new LogicException('GatewayRequest is required');
        }
        $authHeader = (string)$request->header('authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            throw new RuntimeException('Missing or invalid Authorization header', ErrorCode::AUTH_UNAUTHORIZED->value);
        }

        $token = substr($authHeader, 7);
        $res = $this->authClient->verifyToken($token);

        if (!($res['valid'] ?? false)) {
            // 对于注销请求（/api/v1/auth/logout），如果在部分成功的重试过程中设备或令牌已被标记撤销，
            // verifyToken 会返回 false。放行请求至控制器由 AuthService 执行幂等收尾与主体强校验。
            if (rtrim($request->path(), '/') === '/api/v1/auth/logout') {
                return $handler($request);
            }

            throw new RuntimeException('Invalid or expired token', ErrorCode::AUTH_TOKEN_INVALID->value);
        }

        $userId = (string)($res['user_id'] ?? '');
        $deviceId = (string)($res['device_id'] ?? '');
        $requestedPersonaId = $request->header('x-persona-id');

        // 用已认证的用户信息更新 TraceContext。
        // 安全注记：
        // x-user-id 与 x-device-id 来自已验证签名的令牌；
        // 客户端头里的 x-persona-id 是不可信的原始用户输入，
        // 必须先经 UserService 校验归属，才能提升进可信 TraceContext /
        // 内部 gRPC 元数据（在此之前 personaId 一直为 null）。
        $currentTrace = TraceContext::current();
        $updated = TraceContext::create(
            requestId: $currentTrace->requestId,
            traceId: $currentTrace->traceId,
            userId: $userId,
            deviceId: $deviceId ?: $currentTrace->deviceId,
            appVersion: $currentTrace->appVersion,
            traceparent: $currentTrace->traceparent
        );
        TraceContext::setCurrent($updated);

        $request->authContext = [
            'user_id'              => $userId,
            'device_id'            => $deviceId,
            'requested_persona_id' => $requestedPersonaId,
        ];
        return $handler($request);
    }
}
