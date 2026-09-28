<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;

final class PersonaController
{
    public function __construct(private readonly UserServiceClient $userClient)
    {
    }

    public function list(array $authContext): array
    {
        $userId = $authContext['user_id'] ?? '';
        if ($userId === '') {
            return ApiResponse::error(ErrorCode::AUTH_UNAUTHORIZED->value, 'unauthorized');
        }

        $res = $this->userClient->listPersonas($userId);
        return ApiResponse::success([
            'personas' => $res['personas'] ?? [],
        ]);
    }

    public function create(array $body, array $authContext): array
    {
        $userId = $authContext['user_id'] ?? '';
        if ($userId === '') {
            return ApiResponse::error(ErrorCode::AUTH_UNAUTHORIZED->value, 'unauthorized');
        }

        $nickname = trim((string)($body['nickname'] ?? ''));
        if ($nickname === '') {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'nickname is required');
        }

        $type = (int)($body['type'] ?? 1);
        $avatarKey = !empty($body['avatar_key']) ? (string)$body['avatar_key'] : null;
        $bio = !empty($body['bio']) ? (string)$body['bio'] : null;

        $res = $this->userClient->createPersona($userId, $type, $nickname, $avatarKey, $bio);

        return ApiResponse::success($res['persona'] ?? [], 201);
    }

    public function get(string $personaId): array
    {
        if ($personaId === '') {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'persona_id is required');
        }

        $res = $this->userClient->getPersona($personaId);
        return ApiResponse::success($res['persona'] ?? []);
    }

    public function update(string $personaId, array $body, array $authContext): array
    {
        $userId = $authContext['user_id'] ?? '';
        if ($userId === '') {
            return ApiResponse::error(ErrorCode::AUTH_UNAUTHORIZED->value, 'unauthorized');
        }

        $nickname = isset($body['nickname']) ? (string)$body['nickname'] : null;
        $avatarKey = isset($body['avatar_key']) ? (string)$body['avatar_key'] : null;
        $bio = isset($body['bio']) ? (string)$body['bio'] : null;

        $res = $this->userClient->updatePersona($personaId, $userId, $nickname, $avatarKey, $bio);
        return ApiResponse::success($res['persona'] ?? []);
    }
}
