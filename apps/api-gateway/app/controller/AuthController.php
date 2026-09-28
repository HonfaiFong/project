<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;

final class AuthController
{
    public function __construct(
        private readonly AuthServiceClient $authClient,
        private readonly UserServiceClient $userClient
    ) {
    }

    public function login(array $body, array $headers): array
    {
        $phone = trim((string)($body['phone'] ?? ''));
        if ($phone === '') {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'phone is required');
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

    public function logout(array $body): array
    {
        $refreshToken = (string)($body['refresh_token'] ?? '');
        if ($refreshToken === '') {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'refresh_token is required');
        }

        $this->authClient->logout($refreshToken);
        return ApiResponse::success(['success' => true]);
    }

    public function createWsTicket(array $body, array $authContext): array
    {
        $userId = $authContext['user_id'] ?? '';
        $deviceId = $authContext['device_id'] ?? '';
        $personaId = (string)($body['persona_id'] ?? $authContext['persona_id'] ?? '0');

        if ($userId === '') {
            return ApiResponse::error(ErrorCode::AUTH_UNAUTHORIZED->value, 'unauthorized');
        }

        $res = $this->authClient->createWsTicket($userId, $personaId, $deviceId);

        return ApiResponse::success([
            'ticket'     => $res['ticket'],
            'expires_at' => $res['expires_at'],
        ]);
    }
}
