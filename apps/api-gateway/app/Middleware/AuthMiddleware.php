<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Middleware;

use Apps\ApiGateway\Grpc\AuthServiceClient;
use Contracts\Errors\ErrorCode;
use Packages\Tracing\TraceContext;
use RuntimeException;

final class AuthMiddleware
{
    public function __construct(private readonly AuthServiceClient $authClient)
    {
    }

    /**
     * @param array<string, string> $headers
     * @return array{user_id: string, device_id: string, requested_persona_id: ?string}
     */
    public function authenticate(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $k => $v) {
            $normalized[strtolower((string)$k)] = (string)$v;
        }

        $authHeader = $normalized['authorization'] ?? '';
        if (!str_starts_with($authHeader, 'Bearer ')) {
            throw new RuntimeException('Missing or invalid Authorization header', ErrorCode::AUTH_UNAUTHORIZED->value);
        }

        $token = substr($authHeader, 7);
        $res = $this->authClient->verifyToken($token);

        if (!($res['valid'] ?? false)) {
            throw new RuntimeException('Invalid or expired token', ErrorCode::AUTH_TOKEN_INVALID->value);
        }

        $userId = (string)($res['user_id'] ?? '');
        $deviceId = (string)($res['device_id'] ?? '');
        $requestedPersonaId = $normalized['x-persona-id'] ?? null;

        // Update TraceContext with authenticated user info.
        // SECURITY NOTE:
        // x-user-id and x-device-id originate from verified token signature.
        // x-persona-id from client headers is untrusted raw user input.
        // It must NOT be promoted to trusted TraceContext / internal gRPC metadata (personaId: null)
        // until verified against UserService for ownership.
        $currentTrace = TraceContext::current();
        $updated = TraceContext::create(
            requestId: $currentTrace->requestId,
            traceId: $currentTrace->traceId,
            userId: $userId,
            personaId: null,
            deviceId: $deviceId ?: $currentTrace->deviceId,
            appVersion: $currentTrace->appVersion,
            traceparent: $currentTrace->traceparent
        );
        TraceContext::setCurrent($updated);

        return [
            'user_id'              => $userId,
            'device_id'            => $deviceId,
            'requested_persona_id' => $requestedPersonaId,
        ];
    }
}
