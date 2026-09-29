<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Apps\ApiGateway\Action\CreateWsTicketAction;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Http\GatewayRequest;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;
use Webman\Http\Response;

final class AuthController
{
    public function __construct(
        private readonly AuthServiceClient $authClient,
        private readonly CreateWsTicketAction $createWsTicketAction
    ) {
    }

    public function login(GatewayRequest $request): Response
    {
        $body = (array)$request->post();
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
            $device['device_uuid'] = $request->header('x-device-id') ?: 'device-' . bin2hex(random_bytes(8));
        }
        $device['platform'] = $device['platform'] ?? $request->header('x-platform', 'unknown');
        $device['app_version'] = $device['app_version'] ?? $request->header('x-app-version', '1.0.0');

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

    public function refresh(GatewayRequest $request): Response
    {
        $body = (array)$request->post();
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

    public function logout(GatewayRequest $request): Response
    {
        $body = (array)$request->post();
        $refreshToken = (string)($body['refresh_token'] ?? '');
        if ($refreshToken === '') {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'refresh_token is required');
        }

        $accessToken = self::bearerToken((string)$request->header('authorization', ''));
        if ($accessToken === null) {
            return ApiResponse::error(ErrorCode::AUTH_UNAUTHORIZED->value, 'unauthorized');
        }

        $this->authClient->logout($refreshToken, $accessToken);
        return ApiResponse::success(['success' => true]);
    }

    public function createWsTicket(GatewayRequest $request): Response
    {
        $res = $this->createWsTicketAction->execute((array)$request->post(), $request->authContext);

        return ApiResponse::success([
            'ticket'     => $res['ticket'],
            'expires_at' => $res['expires_at'],
        ]);
    }

    private static function bearerToken(string $authorization): ?string
    {
        $authorization = trim($authorization);
        if (!str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authorization, 7));
        return $token === '' ? null : $token;
    }
}
