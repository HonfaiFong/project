<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Grpc;

use Contracts\Gen\Auth\V1\CreateWsTicketRequest;
use Contracts\Gen\Auth\V1\CreateWsTicketResponse;
use Contracts\Gen\Auth\V1\DeviceInfo;
use Contracts\Gen\Auth\V1\LoginRequest;
use Contracts\Gen\Auth\V1\LoginResponse;
use Contracts\Gen\Auth\V1\LogoutRequest;
use Contracts\Gen\Auth\V1\LogoutResponse;
use Contracts\Gen\Auth\V1\RefreshTokenRequest;
use Contracts\Gen\Auth\V1\RefreshTokenResponse;
use Contracts\Gen\Auth\V1\VerifyTokenRequest;
use Contracts\Gen\Auth\V1\VerifyTokenResponse;
use Contracts\Gen\Common\V1\HealthCheckRequest;
use Contracts\Gen\Common\V1\HealthCheckResponse;
use Google\Protobuf\Internal\Message;

final class AuthServiceClient
{
    private ?UnaryGrpcClient $client = null;

    public function __construct(
        private readonly ?string $target = null,
        private readonly int $timeoutMs = 150
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
     * @return T
     */
    private function call(string $method, Message $request, string $responseClass): Message
    {
        $client = $this->client ??= new UnaryGrpcClient(
            $this->target ?: (getenv('GRPC_AUTH_SERVICE') ?: 'auth-service:50051')
        );

        return $client->call($method, $request, $responseClass, $this->timeoutMs);
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
