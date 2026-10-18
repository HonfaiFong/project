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

    /** @throws Exception 上游调用或响应追踪失败时抛出 */
    public function login(GatewayRequest $request): Response
    {
        $phone = trim((string)$request->post('phone', ''));
        if (blank($phone)) {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'phone is required');
        }

        $code = trim((string)$request->post('code', ''));
        $password = (string)$request->post('password', '');
        if (blank($code) && blank($password)) {
            return ApiResponse::error(ErrorCode::AUTH_INVALID_CREDENTIALS->value, ErrorCode::AUTH_INVALID_CREDENTIALS->message());
        }

        $device = $request->loginDevice();

        $authRes = $this->authClient->login($phone, $code, $device, blank($password) ? null : $password);

        $login = $this->loginData($authRes);

        return ApiResponse::success($login, $login['is_new_user'] ? 201 : 200);
    }

    /** @throws Exception 上游调用或响应追踪失败时抛出 */
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

        // 社交登录始终注册或恢复既有用户：新用户返回 201。
        $login = $this->loginData($result['login']);

        return ApiResponse::success([
            'state' => 'authenticated',
            ...$login,
        ], $login['is_new_user'] ? 201 : 200);
    }

    /** @throws Exception */
    public function socialBindIdentity(GatewayRequest $request): Response
    {
        $provider = strtolower(trim((string)$request->post('provider', '')));
        $code = trim((string)$request->post('code', ''));
        if ($error = $this->validateSocialCredentials($provider, $code)) {
            return $error;
        }

        // 被绑定的用户取自 Authorization 访问令牌，绝不来自请求体。
        $accessToken = $request->bearerToken();
        if (blank($accessToken)) {
            return ApiResponse::error(ErrorCode::AUTH_UNAUTHORIZED->value, 'unauthorized');
        }

        $result = $this->authClient->bindSocialIdentity($provider, $code, $accessToken);

        return ApiResponse::success([
            'user_id'  => (string)$result['user_id'],
            'provider' => $result['provider'],
        ]);
    }

    /** @throws Exception */
    public function refresh(GatewayRequest $request): Response
    {
        $refreshToken = (string)$request->post('refresh_token', '');
        if (blank($refreshToken)) {
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

    /** @throws Exception */
    public function logout(GatewayRequest $request): Response
    {
        $refreshToken = (string)$request->post('refresh_token', '');
        if (blank($refreshToken)) {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'refresh_token is required');
        }

        $accessToken = $request->bearerToken();
        if (blank($accessToken)) {
            return ApiResponse::error(ErrorCode::AUTH_UNAUTHORIZED->value, 'unauthorized');
        }

        $res = $this->authClient->logout($refreshToken, $accessToken);
        if (!($res['success'] ?? false)) {
            return ApiResponse::error(ErrorCode::AUTH_TOKEN_INVALID->value, 'invalid refresh token');
        }

        return ApiResponse::success(['success' => true]);
    }

    /** @throws Exception */
    public function createWsTicket(GatewayRequest $request): Response
    {
        $res = $this->createWsTicketAction->execute((array)$request->post(), $request->authContext);

        return ApiResponse::success([
            'ticket'     => $res['ticket'],
            'expires_at' => $res['expires_at'],
        ]);
    }

    /** @throws Exception */
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
