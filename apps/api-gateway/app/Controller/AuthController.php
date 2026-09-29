<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Apps\ApiGateway\Auth\LoginCredentialVerifier;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;
use Packages\Tracing\TraceContext;
use RuntimeException;

final class AuthController
{
    public function __construct(
        private readonly AuthServiceClient $authClient,
        private readonly UserServiceClient $userClient,
        ?LoginCredentialVerifier $credentialVerifier = null
    ) {
        $this->credentialVerifier = $credentialVerifier ?? LoginCredentialVerifier::fromEnvironment();
    }

    private readonly LoginCredentialVerifier $credentialVerifier;

    public function login(array $body, array $headers): array
    {
        $phone = trim((string)($body['phone'] ?? ''));
        if ($phone === '') {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'phone is required');
        }

        try {
            $this->credentialVerifier->verify($phone, $body);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getCode(), $e->getMessage());
        }

        $device = (array)($body['device'] ?? []);
        if (empty($device['device_uuid'])) {
            $device['device_uuid'] = $headers['x-device-id'] ?? 'device-' . bin2hex(random_bytes(8));
        }
        $device['platform'] = $device['platform'] ?? $headers['x-platform'] ?? 'unknown';
        $device['app_version'] = $device['app_version'] ?? $headers['x-app-version'] ?? '1.0.0';

        // 1. Resolve user in UserService
        $userRes = $this->userClient->findOrCreateByPhone($phone);
        $user = $userRes['user'];
        $isNewUser = (bool)($userRes['is_new_user'] ?? false);

        // 2. Issue tokens in AuthService
        $authRes = $this->authClient->loginWithUser((string)$user['id'], $device, $isNewUser);

        return ApiResponse::success([
            'user_id'                  => (string)$user['id'],
            'access_token'             => $authRes['access_token'],
            'refresh_token'            => $authRes['refresh_token'],
            'access_token_expires_at'  => $authRes['access_token_expires_at'],
            'refresh_token_expires_at' => $authRes['refresh_token_expires_at'],
            'is_new_user'              => $isNewUser,
        ], $isNewUser ? 201 : 200);
    }

    public function refresh(array $body): array
    {
        $refreshToken = (string)($body['refresh_token'] ?? '');
        if ($refreshToken === '') {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'refresh_token is required');
        }

        $device = (array)($body['device'] ?? []);
        $res = $this->authClient->refreshToken($refreshToken, $device);

        return ApiResponse::success([
            'access_token'             => $res['access_token'],
            'refresh_token'            => $res['refresh_token'],
            'access_token_expires_at'  => $res['access_token_expires_at'],
            'refresh_token_expires_at' => $res['refresh_token_expires_at'],
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function logout(array $body, array $headers = []): array
    {
        $refreshToken = (string)($body['refresh_token'] ?? '');
        if ($refreshToken === '') {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'refresh_token is required');
        }

        $accessToken = self::bearerToken($headers);
        if ($accessToken === null) {
            return ApiResponse::error(ErrorCode::AUTH_UNAUTHORIZED->value, 'unauthorized');
        }

        $this->authClient->logout($refreshToken, $accessToken);
        return ApiResponse::success(['success' => true]);
    }

    public function createWsTicket(array $body, array $authContext): array
    {
        $userId = $authContext['user_id'] ?? '';
        $deviceId = $authContext['device_id'] ?? '';
        $requestedPersonaId = trim((string)($body['persona_id'] ?? ''));
        $headerPersonaId = trim((string)($authContext['requested_persona_id'] ?? $authContext['persona_id'] ?? ''));

        if ($requestedPersonaId !== '' && $headerPersonaId !== '' && $requestedPersonaId !== $headerPersonaId) {
            return ApiResponse::error(ErrorCode::PERSONA_FORBIDDEN->value, 'forbidden persona access');
        }

        $personaId = $requestedPersonaId !== '' ? $requestedPersonaId : $headerPersonaId;

        if ($userId === '' || $personaId === '' || $personaId === '0') {
            return ApiResponse::error(ErrorCode::AUTH_UNAUTHORIZED->value, 'unauthorized');
        }

        $personaRes = $this->userClient->getPersona($personaId);
        $persona = $personaRes['persona'] ?? null;
        if (!is_array($persona) || (string)($persona['user_id'] ?? '') !== (string)$userId) {
            return ApiResponse::error(ErrorCode::PERSONA_FORBIDDEN->value, 'forbidden persona access');
        }

        if ((int)($persona['status'] ?? 0) !== 1) {
            return ApiResponse::error(ErrorCode::PERSONA_FORBIDDEN->value, 'persona is inactive');
        }

        // Only after ownership is verified against UserService, elevate into trusted TraceContext.
        $currentTrace = TraceContext::current();
        TraceContext::setCurrent(TraceContext::create(
            requestId: $currentTrace->requestId,
            traceId: $currentTrace->traceId,
            userId: $userId,
            personaId: $personaId,
            deviceId: $deviceId ?: $currentTrace->deviceId,
            appVersion: $currentTrace->appVersion,
            traceparent: $currentTrace->traceparent
        ));

        $res = $this->authClient->createWsTicket($userId, $personaId, $deviceId);

        return ApiResponse::success([
            'ticket'     => $res['ticket'],
            'expires_at' => $res['expires_at'],
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function bearerToken(array $headers): ?string
    {
        $authorization = trim((string)($headers['authorization'] ?? ''));
        if (!str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authorization, 7));
        return $token === '' ? null : $token;
    }
}
