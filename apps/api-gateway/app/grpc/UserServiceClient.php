<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Grpc;

use Contracts\Errors\ErrorCode;
use Packages\GrpcCommon\GrpcClientContext;
use RuntimeException;

final class UserServiceClient
{
    private string $host;
    private int $port;
    private int $timeoutMs;

    public function __construct(?string $target = null, int $timeoutMs = 150)
    {
        $target = $target ?: getenv('GRPC_USER_SERVICE') ?: 'user-service:50052';
        $parts = explode(':', $target);
        $this->host = $parts[0];
        $this->port = isset($parts[1]) ? (int)$parts[1] : 50052;
        $this->timeoutMs = $timeoutMs;
    }

    public function findOrCreateByPhone(string $phone): array
    {
        return $this->invoke('FindOrCreateByPhone', [
            'phone' => $phone,
        ]);
    }

    public function getUser(string $userId): array
    {
        return $this->invoke('GetUser', [
            'user_id' => $userId,
        ]);
    }

    public function batchGetUsers(array $userIds): array
    {
        return $this->invoke('BatchGetUsers', [
            'user_ids' => $userIds,
        ]);
    }

    public function createPersona(string $userId, int $type, string $nickname, ?string $avatarKey, ?string $bio): array
    {
        return $this->invoke('CreatePersona', [
            'user_id'    => $userId,
            'type'       => $type,
            'nickname'   => $nickname,
            'avatar_key' => $avatarKey,
            'bio'        => $bio,
        ]);
    }

    public function getPersona(string $personaId): array
    {
        return $this->invoke('GetPersona', [
            'persona_id' => $personaId,
        ]);
    }

    public function batchGetPersonas(array $personaIds): array
    {
        return $this->invoke('BatchGetPersonas', [
            'persona_ids' => $personaIds,
        ]);
    }

    public function updatePersona(string $personaId, string $userId, ?string $nickname, ?string $avatarKey, ?string $bio): array
    {
        return $this->invoke('UpdatePersona', [
            'persona_id' => $personaId,
            'user_id'    => $userId,
            'nickname'   => $nickname,
            'avatar_key' => $avatarKey,
            'bio'        => $bio,
        ]);
    }

    public function listPersonas(string $userId): array
    {
        return $this->invoke('ListPersonas', [
            'user_id' => $userId,
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
            throw new RuntimeException("Failed to connect to UserService ({$this->host}:{$this->port}): {$errstr}", ErrorCode::SERVICE_UNAVAILABLE->value);
        }

        stream_set_timeout($socket, (int)floor($this->timeoutMs / 1000), ($this->timeoutMs % 1000) * 1000);
        fwrite($socket, $payload);

        $response = fgets($socket);
        fclose($socket);

        if ($response === false) {
            throw new RuntimeException('UserService RPC timeout or empty response', ErrorCode::RPC_TIMEOUT->value);
        }

        $res = json_decode(trim($response), true);
        if (!is_array($res)) {
            throw new RuntimeException('Invalid RPC response from UserService', ErrorCode::INTERNAL_SERVER_ERROR->value);
        }

        if (($res['code'] ?? 0) !== 0) {
            $code = (int)$res['code'];
            $msg = (string)($res['message'] ?? 'RPC error');
            throw new RuntimeException($msg, $code);
        }

        return $res;
    }
}
