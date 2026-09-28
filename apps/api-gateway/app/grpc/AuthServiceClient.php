<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Grpc;

use Contracts\Errors\ErrorCode;
use Packages\GrpcCommon\GrpcClientContext;
use RuntimeException;

final class AuthServiceClient
{
    private string $host;
    private int $port;
    private int $timeoutMs;

    public function __construct(?string $target = null, int $timeoutMs = 150)
    {
        $target = $target ?: getenv('GRPC_AUTH_SERVICE') ?: 'auth-service:50051';
        $parts = explode(':', $target);
        $this->host = $parts[0];
        $this->port = isset($parts[1]) ? (int)$parts[1] : 50051;
        $this->timeoutMs = $timeoutMs;
    }

    public function login(string $phone, array $deviceInfo): array
    {
        return $this->invoke('Login', [
            'user_id'     => '', // To be filled or resolved by user-service integration
            'phone'       => $phone,
            'device'      => $deviceInfo,
            'is_new_user' => false,
        ]);
    }

    public function loginWithUser(string $userId, array $deviceInfo, bool $isNewUser): array
    {
        return $this->invoke('Login', [
            'user_id'     => $userId,
            'device'      => $deviceInfo,
            'is_new_user' => $isNewUser,
        ]);
    }

    public function refreshToken(string $refreshToken, array $deviceInfo = []): array
    {
        return $this->invoke('RefreshToken', [
            'refresh_token' => $refreshToken,
            'device'        => $deviceInfo,
        ]);
    }

    public function logout(string $refreshToken): array
    {
        return $this->invoke('Logout', [
            'refresh_token' => $refreshToken,
        ]);
    }

    public function verifyToken(string $accessToken): array
    {
        return $this->invoke('VerifyToken', [
            'access_token' => $accessToken,
        ]);
    }

    public function createWsTicket(string $userId, string $personaId, string $deviceId): array
    {
        return $this->invoke('CreateWsTicket', [
            'user_id'    => $userId,
            'persona_id' => $personaId,
            'device_id'  => $deviceId,
        ]);
    }

    public function checkHealth(): array
    {
        return $this->invoke('Check', []);
    }

    private function invoke(string $method, array $params): array
    {
        $opts = GrpcClientContext::createCallOptions($this->timeoutMs);
        $metadata = $opts['metadata'] ?? [];

        $payload = json_encode([
            'method'   => $method,
            'params'   => $params,
            'metadata' => $metadata,
        ]) . "\n";

        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, (float)($this->timeoutMs / 1000));
        if (!$socket) {
            throw new RuntimeException("Failed to connect to AuthService ({$this->host}:{$this->port}): {$errstr}", ErrorCode::SERVICE_UNAVAILABLE->value);
        }

        stream_set_timeout($socket, (int)floor($this->timeoutMs / 1000), ($this->timeoutMs % 1000) * 1000);
        fwrite($socket, $payload);

        $response = fgets($socket);
        fclose($socket);

        if ($response === false) {
            throw new RuntimeException('AuthService RPC timeout or empty response', ErrorCode::RPC_TIMEOUT->value);
        }

        $res = json_decode(trim($response), true);
        if (!is_array($res)) {
            throw new RuntimeException('Invalid RPC response from AuthService', ErrorCode::INTERNAL_SERVER_ERROR->value);
        }

        if (($res['code'] ?? 0) !== 0) {
            $code = (int)$res['code'];
            $msg = (string)($res['message'] ?? 'RPC error');
            throw new RuntimeException($msg, $code);
        }

        return $res;
    }
}
