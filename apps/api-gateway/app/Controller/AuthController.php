<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Apps\ApiGateway\Action\CreateWsTicketAction;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Http\GatewayRequest;
use Apps\ApiGateway\Http\TrustedProxyClientIpResolver;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;
use Webman\Http\Response;

final class AuthController
{
    private readonly TrustedProxyClientIpResolver $clientIpResolver;

    public function __construct(
        private readonly AuthServiceClient $authClient,
        private readonly CreateWsTicketAction $createWsTicketAction,
        ?TrustedProxyClientIpResolver $clientIpResolver = null,
    ) {
        $this->clientIpResolver = $clientIpResolver ?? new TrustedProxyClientIpResolver();
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

        $device = $this->resolveDevice($request, $body);

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

    public function socialLogin(GatewayRequest $request): Response
    {
        $body = (array)$request->post();
        $provider = strtolower(trim((string)($body['provider'] ?? '')));
        if (!in_array($provider, ['wechat', 'qq'], true)) {
            return ApiResponse::error(
                ErrorCode::AUTH_SOCIAL_PROVIDER_UNSUPPORTED->value,
                ErrorCode::AUTH_SOCIAL_PROVIDER_UNSUPPORTED->message()
            );
        }

        $code = trim((string)($body['code'] ?? ''));
        if ($code === '') {
            return ApiResponse::error(
                ErrorCode::AUTH_SOCIAL_CODE_INVALID->value,
                ErrorCode::AUTH_SOCIAL_CODE_INVALID->message()
            );
        }

        $result = $this->authClient->socialLogin(
            $provider,
            $code,
            $this->resolveDevice($request, $body),
        );

        if ($result['state'] === 'phone_binding_required') {
            return ApiResponse::success([
                'state'                  => $result['state'],
                'bind_token'             => $result['bind_token'],
                'bind_token_expires_at'  => $result['bind_token_expires_at'],
            ]);
        }

        return ApiResponse::success([
            'state' => 'authenticated',
            ...$this->loginData($result['login']),
        ]);
    }

    public function sendSocialPhoneCode(GatewayRequest $request): Response
    {
        $body = (array)$request->post();
        $bindToken = trim((string)($body['bind_token'] ?? ''));
        $phone = trim((string)($body['phone'] ?? ''));
        if ($bindToken === '' || $phone === '') {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'bind_token and phone are required');
        }

        $device = $this->resolveDevice($request, $body);
        $result = $this->authClient->sendSocialPhoneCode($bindToken, $phone, [
            'ip'          => $this->clientIpResolver->resolve($request),
            'device_uuid' => $device['device_uuid'] ?? null,
        ]);

        return ApiResponse::success($result);
    }

    public function bindSocialPhone(GatewayRequest $request): Response
    {
        $body = (array)$request->post();
        $bindToken = trim((string)($body['bind_token'] ?? ''));
        $phone = trim((string)($body['phone'] ?? ''));
        if ($bindToken === '' || $phone === '') {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'bind_token and phone are required');
        }

        $result = $this->authClient->bindSocialPhone(
            $bindToken,
            $phone,
            trim((string)($body['code'] ?? '')),
            $this->resolveDevice($request, $body),
        );

        $login = $this->loginData($result['login']);

        return ApiResponse::success($login, $login['is_new_user'] ? 201 : 200);
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

    /** @param array<string, mixed> $body */
    private function resolveDevice(GatewayRequest $request, array $body): array
    {
        $device = (array)($body['device'] ?? []);
        if (empty($device['device_uuid'])) {
            $device['device_uuid'] = $request->header('x-device-id') ?: 'device-' . bin2hex(random_bytes(8));
        }
        $device['platform'] = $device['platform'] ?? $request->header('x-platform', 'unknown');
        $device['app_version'] = $device['app_version'] ?? $request->header('x-app-version', '1.0.0');

        return $device;
    }

    /** @param array<string, mixed> $login */
    private function loginData(array $login): array
    {
        return [
            'user_id'                  => (string)$login['user_id'],
            'access_token'             => $login['access_token'],
            'refresh_token'            => $login['refresh_token'],
            'access_token_expires_at'  => $login['access_token_expires_at'],
            'refresh_token_expires_at' => $login['refresh_token_expires_at'],
            'is_new_user'              => (bool)($login['is_new_user'] ?? false),
        ];
    }
}
