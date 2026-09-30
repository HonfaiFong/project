<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Grpc;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\Auth\V1\CreateWsTicketRequest;
use Contracts\Gen\Auth\V1\CreateWsTicketResponse;
use Contracts\Gen\Auth\V1\BindSocialPhoneRequest;
use Contracts\Gen\Auth\V1\BindSocialPhoneResponse;
use Contracts\Gen\Auth\V1\DeviceInfo;
use Contracts\Gen\Auth\V1\LoginRequest;
use Contracts\Gen\Auth\V1\LoginResponse;
use Contracts\Gen\Auth\V1\LogoutRequest;
use Contracts\Gen\Auth\V1\LogoutResponse;
use Contracts\Gen\Auth\V1\RefreshTokenRequest;
use Contracts\Gen\Auth\V1\RefreshTokenResponse;
use Contracts\Gen\Auth\V1\SendSocialPhoneCodeRequest;
use Contracts\Gen\Auth\V1\SendSocialPhoneCodeResponse;
use Contracts\Gen\Auth\V1\SocialLoginRequest;
use Contracts\Gen\Auth\V1\SocialLoginResponse;
use Contracts\Gen\Auth\V1\VerifyTokenRequest;
use Contracts\Gen\Auth\V1\VerifyTokenResponse;
use Contracts\Gen\Common\V1\HealthCheckRequest;
use Contracts\Gen\Common\V1\HealthCheckResponse;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use RuntimeException;
use Apps\ApiGateway\Action\WsTicketClient;
use const Grpc\STATUS_DEADLINE_EXCEEDED;
use const Grpc\STATUS_INVALID_ARGUMENT;
use const Grpc\STATUS_RESOURCE_EXHAUSTED;
use const Grpc\STATUS_UNAUTHENTICATED;

final class AuthServiceClient implements HealthCheckClient, WsTicketClient
{
    private ?UnaryGrpcClient $client = null;

    public function __construct(
        private readonly ?string $target = null,
        private readonly int $timeoutMs = 300
    ) {
    }

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
     * @return array{state: string, bind_token?: string, bind_token_expires_at?: string, login?: array<string, mixed>}
     */
    public function socialLogin(string $provider, string $code, array $deviceInfo): array
    {
        $providerValue = match (strtolower(trim($provider))) {
            'wechat' => 1,
            'qq' => 2,
            default => throw new RuntimeException(
                ErrorCode::AUTH_SOCIAL_PROVIDER_UNSUPPORTED->message(),
                ErrorCode::AUTH_SOCIAL_PROVIDER_UNSUPPORTED->value
            ),
        };

        $response = $this->call('/auth.v1.AuthService/SocialLogin', new SocialLoginRequest([
            'provider' => $providerValue,
            'code'     => $code,
            'device'   => $this->device($deviceInfo),
        ]), SocialLoginResponse::class);

        if ($response->getState() === 1) {
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

        return [
            'state'                  => 'phone_binding_required',
            'bind_token'             => $response->getBindToken(),
            'bind_token_expires_at'  => $response->getBindTokenExpiresAt(),
        ];
    }

    /**
     * @param array{ip?: ?string, device_uuid?: ?string} $context
     * @return array{expires_in: int, resend_after: int}
     */
    public function sendSocialPhoneCode(string $bindToken, string $phone, array $context = []): array
    {
        $response = $this->call('/auth.v1.AuthService/SendSocialPhoneCode', new SendSocialPhoneCodeRequest([
            'bind_token' => $bindToken,
            'phone'      => $phone,
        ]), SendSocialPhoneCodeResponse::class, [
            'x-client-ip' => (string)($context['ip'] ?? ''),
            'x-device-id' => (string)($context['device_uuid'] ?? ''),
        ]);

        return [
            'expires_in'   => $response->getExpiresIn(),
            'resend_after' => $response->getResendAfter(),
        ];
    }

    /** @return array{state: string, login: array<string, mixed>} */
    public function bindSocialPhone(
        string $bindToken,
        string $phone,
        string $code,
        array $deviceInfo
    ): array {
        $response = $this->call('/auth.v1.AuthService/BindSocialPhone', new BindSocialPhoneRequest([
            'bind_token' => $bindToken,
            'phone'      => $phone,
            'code'       => $code,
            'device'     => $this->device($deviceInfo),
        ]), BindSocialPhoneResponse::class);
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

    public function logout(string $refreshToken, ?string $accessToken = null): array
    {
        $response = $this->call('/auth.v1.AuthService/Logout', new LogoutRequest([
            'refresh_token' => $refreshToken,
            'access_token'  => $accessToken ?? '',
        ]), LogoutResponse::class);

        return ['success' => $response->getSuccess()];
    }

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
     * @param array<string, string|array<string>> $metadata
     * @return T
     */
    private function call(string $method, Message $request, string $responseClass, array $metadata = []): Message
    {
        $client = $this->client ??= new UnaryGrpcClient(
            $this->target ?: (getenv('GRPC_AUTH_SERVICE') ?: 'auth-service:50051')
        );

        try {
            return $client->call($method, $request, $responseClass, $this->timeoutMs, $metadata);
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

        return new RuntimeException($e->getMessage(), $code, $e);
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
