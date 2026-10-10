<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Apps\ApiGateway\Action\CreateWsTicketAction;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Http\GatewayRequest;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;
use Exception;
use Illuminate\Contracts\Validation\Factory;
use Webman\Http\Response;

final readonly class AuthController
{
    public function __construct(
        private AuthServiceClient $authClient,
        private CreateWsTicketAction $createWsTicketAction,
        private Factory $validator,
    ) {
    }

    /** @throws Exception If the secure random source is unavailable. */
    public function login(GatewayRequest $request): Response
    {
        $phone = trim((string)$request->post('phone', ''));
        if ($phone === '') {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'phone is required');
        }

        $code = trim((string)$request->post('code', ''));
        $password = (string)$request->post('password', '');
        if ($code === '' && $password === '') {
            return ApiResponse::error(ErrorCode::AUTH_INVALID_CREDENTIALS->value, ErrorCode::AUTH_INVALID_CREDENTIALS->message());
        }

        $device = $request->loginDevice();

        $authRes = $this->authClient->login($phone, $code, $device, $password !== '' ? $password : null);

        $login = $this->loginData($authRes);

        return ApiResponse::success($login, $login['is_new_user'] ? 201 : 200);
    }

    /** @throws Exception If the secure random source is unavailable. */
    public function socialLogin(GatewayRequest $request): Response
    {
        $provider = strtolower(trim((string)$request->post('provider', '')));
        $code = trim((string)$request->post('code', ''));
        if ($error = $this->validateSocialCredentials($provider, $code)) {
            return $error;
        }

        $result = $this->authClient->socialLogin(
            $provider,
            $code,
            $request->loginDevice(),
        );

        // Social login always registers or resumes the user: new users get 201.
        $login = $this->loginData($result['login']);

        return ApiResponse::success([
            'state' => 'authenticated',
            ...$login,
        ], $login['is_new_user'] ? 201 : 200);
    }

    public function socialBindIdentity(GatewayRequest $request): Response
    {
        $provider = strtolower(trim((string)$request->post('provider', '')));
        $code = trim((string)$request->post('code', ''));
        if ($error = $this->validateSocialCredentials($provider, $code)) {
            return $error;
        }

        // The bound user comes from the Authorization access token, never from the body.
        $accessToken = $request->bearerToken();
        if ($accessToken === null) {
            return ApiResponse::error(ErrorCode::AUTH_UNAUTHORIZED->value, 'unauthorized');
        }

        $result = $this->authClient->bindSocialIdentity($provider, $code, $accessToken);

        return ApiResponse::success([
            'user_id'  => (string)$result['user_id'],
            'provider' => $result['provider'],
        ]);
    }

    public function refresh(GatewayRequest $request): Response
    {
        $refreshToken = (string)$request->post('refresh_token', '');
        if ($refreshToken === '') {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'refresh_token is required');
        }

        $device = (array)$request->post('device', []);
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
        $refreshToken = (string)$request->post('refresh_token', '');
        if ($refreshToken === '') {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'refresh_token is required');
        }

        $accessToken = $request->bearerToken();
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

    private function validateSocialCredentials(string $provider, string $code): ?Response
    {
        $validation = $this->validator->make(
            ['provider' => $provider, 'code' => $code],
            ['provider' => ['required', 'in:wechat,qq'], 'code' => ['required']],
        );

        if (!$validation->fails()) {
            return null;
        }

        $error = $validation->errors()->has('provider')
            ? ErrorCode::AUTH_SOCIAL_PROVIDER_UNSUPPORTED
            : ErrorCode::AUTH_SOCIAL_CODE_INVALID;

        return ApiResponse::error($error->value, $error->message());
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
