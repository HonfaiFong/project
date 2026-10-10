<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Grpc;

use Contracts\Errors\ErrorCode;
use Exception;
use Contracts\Gen\Auth\V1\BindSocialIdentityRequest;
use Contracts\Gen\Auth\V1\BindSocialIdentityResponse;
use Contracts\Gen\Auth\V1\CreateWsTicketRequest;
use Contracts\Gen\Auth\V1\CreateWsTicketResponse;
use Contracts\Gen\Auth\V1\DeviceInfo;
use Contracts\Gen\Auth\V1\LoginRequest;
use Contracts\Gen\Auth\V1\LoginResponse;
use Contracts\Gen\Auth\V1\LogoutRequest;
use Contracts\Gen\Auth\V1\LogoutResponse;
use Contracts\Gen\Auth\V1\RefreshTokenRequest;
use Contracts\Gen\Auth\V1\RefreshTokenResponse;
use Contracts\Gen\Auth\V1\SocialLoginRequest;
use Contracts\Gen\Auth\V1\SocialLoginResponse;
use Contracts\Gen\Auth\V1\SocialProvider;
use Contracts\Gen\Auth\V1\VerifyTokenRequest;
use Contracts\Gen\Auth\V1\VerifyTokenResponse;
use Contracts\Gen\Common\V1\HealthCheckRequest;
use Contracts\Gen\Common\V1\HealthCheckResponse;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use InvalidArgumentException;
use RuntimeException;
use Apps\ApiGateway\Action\WsTicketClient;
use const Grpc\STATUS_DEADLINE_EXCEEDED;
use const Grpc\STATUS_INVALID_ARGUMENT;
use const Grpc\STATUS_RESOURCE_EXHAUSTED;
use const Grpc\STATUS_UNAUTHENTICATED;

final class AuthServiceClient implements HealthCheckClient, WsTicketClient
{
    private ?UnaryGrpcClient $client;

    public function __construct(
        private readonly ?string $target = null,
        private readonly int $timeoutMs = 300,
        private readonly int $socialLoginTimeoutMs = 7000,
        private readonly int $socialBindTimeoutMs = 7000,
        int $socialHttpTimeoutMs = 3000,
        ?UnaryGrpcClient $client = null,
        private readonly ?string $serviceToken = null,
    ) {
        self::validateTimeouts($timeoutMs, $socialLoginTimeoutMs, $socialBindTimeoutMs, $socialHttpTimeoutMs);
        $this->client = $client;
    }

    public static function validateTimeouts(
        int $timeoutMs,
        int $socialLoginTimeoutMs,
        int $socialBindTimeoutMs,
        int $socialHttpTimeoutMs,
    ): void {
        foreach ([
            'GRPC_AUTH_TIMEOUT_MS' => $timeoutMs,
            'GRPC_SOCIAL_LOGIN_TIMEOUT_MS' => $socialLoginTimeoutMs,
            'GRPC_SOCIAL_BIND_TIMEOUT_MS' => $socialBindTimeoutMs,
            'SOCIAL_HTTP_TIMEOUT_MS' => $socialHttpTimeoutMs,
        ] as $setting => $milliseconds) {
            if ($milliseconds < 1) {
                throw new InvalidArgumentException(
                    $setting . ' must resolve to a positive deadline in milliseconds'
                );
            }
        }

        if ($socialLoginTimeoutMs <= 2 * $socialHttpTimeoutMs) {
            throw new InvalidArgumentException(
                'SocialLogin gRPC deadline must exceed two social HTTP timeouts'
            );
        }

        // 绑定走的是与登录同样的最坏情况 OAuth 流程（QQ 会发两次 HTTP 请求），
        // 且要验证 access token 两次，因此需要同样的预算。
        if ($socialBindTimeoutMs <= 2 * $socialHttpTimeoutMs) {
            throw new InvalidArgumentException(
                'SocialBindIdentity gRPC deadline must exceed two social HTTP timeouts'
            );
        }
    }

    /** @throws Exception */
    public function login(string $phone, string $code, array $deviceInfo, ?string $password = null): array
    {
        $response = $this->call('/auth.v1.AuthService/Login', new LoginRequest([
            'phone'    => $phone,
            'code'     => $code,
            'password' => $password ?? '',
            'device'   => $this->device($deviceInfo),
        ]), LoginResponse::class);

        return $this->loginResponse($response);
    }

    /**
     * @return array{state: string, login: array<string, mixed>}
     * @throws Exception
     */
    public function socialLogin(string $provider, string $code, array $deviceInfo): array
    {
        $response = $this->call('/auth.v1.AuthService/SocialLogin', new SocialLoginRequest([
            'provider' => $this->providerValue($provider),
            'code'     => $code,
            'device'   => $this->device($deviceInfo),
        ]), SocialLoginResponse::class, $this->socialLoginTimeoutMs);

        $login = $response->getLogin();
        if ($login === null) {
            throw new RuntimeException(
                ErrorCode::SERVICE_UNAVAILABLE->message(),
                ErrorCode::SERVICE_UNAVAILABLE->value
            );
        }

        return [
            'state' => 'authenticated',
            'login' => $this->loginResponse($login),
        ];
    }

    /**
     * 把服务商身份绑定到给定 access token 背后的用户上。
     * 用户 ID 绝不从请求体获取。
     *
     * @return array{user_id: string, provider: string}
     * @throws Exception
     */
    public function bindSocialIdentity(string $provider, string $code, string $accessToken): array
    {
        $response = $this->call('/auth.v1.AuthService/BindSocialIdentity', new BindSocialIdentityRequest([
            'provider'     => $this->providerValue($provider),
            'code'         => $code,
            'access_token' => $accessToken,
        ]), BindSocialIdentityResponse::class, $this->socialBindTimeoutMs);

        return [
            'user_id'  => (string)$response->getUserId(),
            'provider' => $this->providerName($response->getProvider()),
        ];
    }

    /** @throws Exception */
    public function refreshToken(string $refreshToken, array $deviceInfo = []): array
    {
        $response = $this->call('/auth.v1.AuthService/RefreshToken', new RefreshTokenRequest([
            'refresh_token' => $refreshToken,
            'device'        => $this->device($deviceInfo),
        ]), RefreshTokenResponse::class);

        return [
            'access_token'             => $response->getAccessToken(),
            'refresh_token'            => $response->getRefreshToken(),
            'access_token_expires_at'  => $response->getAccessTokenExpiresAt(),
            'refresh_token_expires_at' => $response->getRefreshTokenExpiresAt(),
        ];
    }

    /** @throws Exception */
    public function logout(string $refreshToken, ?string $accessToken = null): array
    {
        $response = $this->call('/auth.v1.AuthService/Logout', new LogoutRequest([
            'refresh_token' => $refreshToken,
            'access_token'  => $accessToken ?? '',
        ]), LogoutResponse::class);

        return ['success' => $response->getSuccess()];
    }

    /** @throws Exception */
    public function verifyToken(string $accessToken): array
    {
        $response = $this->call('/auth.v1.AuthService/VerifyToken', new VerifyTokenRequest([
            'access_token' => $accessToken,
        ]), VerifyTokenResponse::class);

        return [
            'valid'     => $response->getValid(),
            'user_id'   => (string)$response->getUserId(),
            'device_id' => (string)$response->getDeviceId(),
        ];
    }

    /** @throws Exception */
    public function createWsTicket(string $userId, string $personaId, string $deviceId): array
    {
        $response = $this->call('/auth.v1.AuthService/CreateWsTicket', new CreateWsTicketRequest([
            'user_id'    => $userId,
            'persona_id' => $personaId,
            'device_id'  => $deviceId,
        ]), CreateWsTicketResponse::class);

        return [
            'ticket'     => $response->getTicket(),
            'expires_at' => $response->getExpiresAt(),
        ];
    }

    /** @throws Exception */
    public function checkHealth(): array
    {
        $response = $this->call('/common.v1.HealthService/Check', new HealthCheckRequest(), HealthCheckResponse::class);

        return [
            'status'  => $response->getStatus(),
            'message' => $response->getMessage(),
        ];
    }

    /**
     * @template T of Message
     * @param class-string<T> $responseClass
     * @return T
     * @throws Exception
     */
    private function call(
        string $method,
        Message $request,
        string $responseClass,
        ?int $timeoutMs = null
    ): Message
    {
        $client = $this->client ??= new UnaryGrpcClient(
            $this->target ?: config('gateway.grpc.auth.target', 'auth-service:50051'),
            $this->serviceToken,
        );

        try {
            return $client->call($method, $request, $responseClass, $timeoutMs ?? $this->timeoutMs);
        } catch (GrpcCallException $e) {
            throw $this->mapGrpcCallException($e);
        }
    }

    private function mapGrpcCallException(GrpcCallException $e): RuntimeException
    {
        if ($e->appErrorCode !== null) {
            return new RuntimeException($e->getMessage(), $e->appErrorCode, $e);
        }

        $code = match ($e->grpcStatus) {
            STATUS_DEADLINE_EXCEEDED => ErrorCode::RPC_TIMEOUT->value,
            STATUS_UNAUTHENTICATED => ($e->getMessage() === ErrorCode::AUTH_INVALID_CREDENTIALS->message()
                ? ErrorCode::AUTH_INVALID_CREDENTIALS->value
                : ErrorCode::AUTH_UNAUTHORIZED->value),
            STATUS_INVALID_ARGUMENT => ErrorCode::INVALID_ARGUMENT->value,
            STATUS_RESOURCE_EXHAUSTED => ErrorCode::RATE_LIMIT_EXCEEDED->value,
            default => ErrorCode::SERVICE_UNAVAILABLE->value,
        };

        $message = in_array($code, [ErrorCode::SERVICE_UNAVAILABLE->value, ErrorCode::RPC_TIMEOUT->value], true)
            ? ErrorCode::from($code)->message()
            : $e->getMessage();

        return new RuntimeException($message, $code, $e);
    }

    private function device(array $deviceInfo): DeviceInfo
    {
        return new DeviceInfo([
            'device_uuid' => (string)($deviceInfo['device_uuid'] ?? ''),
            'platform'    => (string)($deviceInfo['platform'] ?? ''),
            'app_version' => (string)($deviceInfo['app_version'] ?? ''),
            'push_token'  => (string)($deviceInfo['push_token'] ?? ''),
        ]);
    }

    private function providerValue(string $provider): int
    {
        return match (strtolower(trim($provider))) {
            'wechat' => SocialProvider::SOCIAL_PROVIDER_WECHAT,
            'qq' => SocialProvider::SOCIAL_PROVIDER_QQ,
            default => throw new RuntimeException(
                ErrorCode::AUTH_SOCIAL_PROVIDER_UNSUPPORTED->message(),
                ErrorCode::AUTH_SOCIAL_PROVIDER_UNSUPPORTED->value
            ),
        };
    }

    private function providerName(int $provider): string
    {
        return match ($provider) {
            SocialProvider::SOCIAL_PROVIDER_WECHAT => 'wechat',
            SocialProvider::SOCIAL_PROVIDER_QQ => 'qq',
            default => throw new RuntimeException(
                ErrorCode::SERVICE_UNAVAILABLE->message(),
                ErrorCode::SERVICE_UNAVAILABLE->value
            ),
        };
    }

    /** @return array<string, mixed> */
    private function loginResponse(LoginResponse $response): array
    {
        return [
            'user_id'                  => (string)$response->getUserId(),
            'access_token'             => $response->getAccessToken(),
            'refresh_token'            => $response->getRefreshToken(),
            'access_token_expires_at'  => $response->getAccessTokenExpiresAt(),
            'refresh_token_expires_at' => $response->getRefreshTokenExpiresAt(),
            'is_new_user'              => $response->getIsNewUser(),
        ];
    }
}
