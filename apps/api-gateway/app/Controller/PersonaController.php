<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Http\GatewayRequest;
use Apps\ApiGateway\Response\ApiResponse;
use Apps\ApiGateway\Response\PersonaPresenter;
use Contracts\Errors\ErrorCode;
use Webman\Http\Response;

final class PersonaController
{
    public function __construct(private readonly UserServiceClient $userClient)
    {
    }

    public function list(GatewayRequest $request): Response
    {
        $userId = $request->authContext['user_id'];
        if ($userId === '') {
            return ApiResponse::error(ErrorCode::AUTH_UNAUTHORIZED->value, 'unauthorized');
        }

        $res = $this->userClient->listPersonas($userId);
        return ApiResponse::success([
            'personas' => PersonaPresenter::many($res['personas'] ?? []),
        ]);
    }

    public function create(GatewayRequest $request): Response
    {
        $body = (array)$request->post();
        $userId = $request->authContext['user_id'];
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

        return ApiResponse::success(PersonaPresenter::one($res['persona'] ?? []), 201);
    }

    public function get(GatewayRequest $request, string $personaId): Response
    {
        if ($personaId === '') {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'persona_id is required');
        }

        $res = $this->userClient->getPersona($personaId);
        return ApiResponse::success(PersonaPresenter::one($res['persona'] ?? []));
    }

    public function update(GatewayRequest $request, string $personaId): Response
    {
        $body = (array)$request->post();
        $userId = $request->authContext['user_id'];
        if ($userId === '') {
            return ApiResponse::error(ErrorCode::AUTH_UNAUTHORIZED->value, 'unauthorized');
        }

        $nickname = isset($body['nickname']) ? (string)$body['nickname'] : null;
        $avatarKey = isset($body['avatar_key']) ? (string)$body['avatar_key'] : null;
        $bio = isset($body['bio']) ? (string)$body['bio'] : null;

        $res = $this->userClient->updatePersona($personaId, $userId, $nickname, $avatarKey, $bio);
        return ApiResponse::success(PersonaPresenter::one($res['persona'] ?? []));
    }
}
