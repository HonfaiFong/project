<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Apps\ApiGateway\Action\CreateWsTicketAction;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;

final class AuthController
{
    private readonly CreateWsTicketAction $createWsTicketAction;

    public function __construct(
        private readonly AuthServiceClient $authClient,
        private readonly UserServiceClient $userClient,
        ?CreateWsTicketAction $createWsTicketAction = null
    ) {
        $this->createWsTicketAction = $createWsTicketAction ?? new CreateWsTicketAction($authClient, $userClient);
    }

    public function login(array $body, array $headers): array
    {
        $phone = trim((string)($body['phone'] ?? ''));
        if ($phone === '') {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'phone is required');
        }

        $code = trim((string)($body['code'] ?? ''));
        $password = (string)($body['password'] ?? '');
        if ($code === '' && $password === '') {
            return ApiResponse::error(ErrorCode::AUTH_INVALID_CREDENTIALS->value, ErrorCode::AUTH_INVALID_CREDENTIALS->message());
        }

        $device = (array)($body['device'] ?? []);
        if (empty($device['device_uuid'])) {
            $device['device_uuid'] = $headers['x-device-id'] ?? 'device-' . bin2hex(random_bytes(8));
        }
        $device['platform'] = $device['platform'] ?? $headers['x-platform'] ?? 'unknown';
        $device['app_version'] = $device['app_version'] ?? $headers['x-app-version'] ?? '1.0.0';

        $authRes = $this->authClient->login($phone, $code, $device, $password !== '' ? $password : null);

        $isNewUser = (bool)($authRes['is_new_user'] ?? false);

        return ApiResponse::success([
            'user_id'                  => (string)$authRes['user_id'],
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
        $res = $this->createWsTicketAction->execute($body, $authContext);

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
