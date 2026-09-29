<?php

declare(strict_types=1);

namespace Services\AuthService\Transport\Grpc;

use Contracts\Errors\ErrorCode;
use Packages\Logging\JsonLogger;
use Packages\Tracing\TraceContext;
use Services\AuthService\Application\Service\AuthApplicationService;
use Throwable;

final class AuthGrpcHandler
{
    public function __construct(
        private readonly AuthApplicationService $authService,
        private readonly JsonLogger $logger
    ) {
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function handle(string $method, array $request, array $metadata = []): array
    {
        // Extract and propagate trace context
        $trace = TraceContext::create(
            requestId: $metadata['x-request-id'][0] ?? $metadata['x-request-id'] ?? null,
            traceId: $metadata['x-trace-id'][0] ?? $metadata['x-trace-id'] ?? null,
            userId: $metadata['x-user-id'][0] ?? $metadata['x-user-id'] ?? null,
            personaId: $metadata['x-persona-id'][0] ?? $metadata['x-persona-id'] ?? null,
            deviceId: $metadata['x-device-id'][0] ?? $metadata['x-device-id'] ?? null,
            appVersion: $metadata['x-app-version'][0] ?? $metadata['x-app-version'] ?? null,
            traceparent: $metadata['traceparent'][0] ?? $metadata['traceparent'] ?? null,
        );
        TraceContext::setCurrent($trace);

        $this->logger->info("Handling Auth gRPC call: {$method}", ['method' => $method]);

        try {
            return match ($method) {
                'Login', 'auth.v1.AuthService/Login' => $this->login($request),
                'RefreshToken', 'auth.v1.AuthService/RefreshToken' => $this->refreshToken($request),
                'Logout', 'auth.v1.AuthService/Logout' => $this->logout($request),
                'VerifyToken', 'auth.v1.AuthService/VerifyToken' => $this->verifyToken($request),
                'CreateWsTicket', 'auth.v1.AuthService/CreateWsTicket' => $this->createWsTicket($request),
                'Check', 'common.v1.HealthService/Check' => $this->checkHealth($request),
                default => [
                    'code'    => ErrorCode::INVALID_ARGUMENT->value,
                    'message' => "Unknown gRPC method: {$method}",
                ],
            };
        } catch (Throwable $e) {
            $this->logger->error("Error in {$method}: " . $e->getMessage(), ['exception' => $e::class]);
            $code = $e->getCode() > 0 ? (int)$e->getCode() : ErrorCode::INTERNAL_SERVER_ERROR->value;
            return [
                'code'    => $code,
                'message' => $e->getMessage(),
            ];
        } finally {
            TraceContext::reset();
        }
    }

    private function login(array $req): array
    {
        $phone = trim((string)($req['phone'] ?? ''));
        $code = trim((string)($req['code'] ?? ''));
        $password = (string)($req['password'] ?? '');
        $device = (array)($req['device'] ?? []);

        $res = $this->authService->login(
            $phone,
            $code,
            $device,
            $password !== '' ? $password : null
        );

        return [
            'code'                     => 0,
            'user_id'                  => $res['user_id'],
            'access_token'             => $res['access_token'],
            'refresh_token'            => $res['refresh_token'],
            'access_token_expires_at'  => $res['access_token_expires_at'],
            'refresh_token_expires_at' => $res['refresh_token_expires_at'],
            'is_new_user'              => $res['is_new_user'],
        ];
    }

    private function refreshToken(array $req): array
    {
        $token = (string)($req['refresh_token'] ?? '');
        $device = (array)($req['device'] ?? []);

        $res = $this->authService->refreshToken($token, $device);

        return [
            'code'                     => 0,
            'access_token'             => $res['access_token'],
            'refresh_token'            => $res['refresh_token'],
            'access_token_expires_at'  => $res['access_token_expires_at'],
            'refresh_token_expires_at' => $res['refresh_token_expires_at'],
        ];
    }

    private function logout(array $req): array
    {
        $refreshToken = (string)($req['refresh_token'] ?? '');
        $accessToken = (string)($req['access_token'] ?? '');
        $success = $this->authService->logout(
            $refreshToken,
            $accessToken !== '' ? $accessToken : null
        );

        return [
            'code'    => 0,
            'success' => $success,
        ];
    }

    private function verifyToken(array $req): array
    {
        $token = (string)($req['access_token'] ?? '');
        $res = $this->authService->verifyToken($token);

        return [
            'code'      => 0,
            'valid'     => $res['valid'],
            'user_id'   => $res['user_id'] ?? '',
            'device_id' => $res['device_id'] ?? '',
        ];
    }

    private function createWsTicket(array $req): array
    {
        $userId = (string)($req['user_id'] ?? '');
        $personaId = (string)($req['persona_id'] ?? '');
        $deviceId = (string)($req['device_id'] ?? '');

        $res = $this->authService->createWsTicket($userId, $personaId, $deviceId);

        return [
            'code'       => 0,
            'ticket'     => $res['ticket'],
            'expires_at' => $res['expire_at'],
        ];
    }

    private function checkHealth(array $req): array
    {
        return [
            'status'  => 1, // HEALTH_STATUS_SERVING
            'message' => 'AuthService is healthy',
        ];
    }
}
