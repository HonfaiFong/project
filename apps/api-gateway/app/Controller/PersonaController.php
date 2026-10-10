<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Http\GatewayRequest;
use Apps\ApiGateway\Response\ApiResponse;
use Apps\ApiGateway\Response\PersonaPresenter;
use Contracts\Errors\ErrorCode;
use Webman\Http\Response;

final readonly class PersonaController
{
    public function __construct(private UserServiceClient $userClient)
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
        $userId = $request->authContext['user_id'];
        if ($userId === '') {
            return ApiResponse::error(ErrorCode::AUTH_UNAUTHORIZED->value, 'unauthorized');
        }

        $nickname = trim((string)$request->post('nickname', ''));
        if ($nickname === '') {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'nickname is required');
        }

        $type = (int)$request->post('type', 1);
        $avatarKey = $request->post('avatar_key') ? (string)$request->post('avatar_key') : null;
        $bio = $request->post('bio') ? (string)$request->post('bio') : null;

        $res = $this->userClient->createPersona($userId, $type, $nickname, $avatarKey, $bio);

        return ApiResponse::success(PersonaPresenter::one($res['persona'] ?? []), 201);
    }

    public function get(string $personaId): Response
    {
        if ($personaId === '') {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'persona_id is required');
        }

        $res = $this->userClient->getPersona($personaId);
        return ApiResponse::success(PersonaPresenter::one($res['persona'] ?? []));
    }

    public function update(GatewayRequest $request, string $personaId): Response
    {
        $userId = $request->authContext['user_id'];
        if ($userId === '') {
            return ApiResponse::error(ErrorCode::AUTH_UNAUTHORIZED->value, 'unauthorized');
        }

        $nickname = $request->post('nickname') !== null ? (string)$request->post('nickname') : null;
        $avatarKey = $request->post('avatar_key') !== null ? (string)$request->post('avatar_key') : null;
        $bio = $request->post('bio') !== null ? (string)$request->post('bio') : null;

        $res = $this->userClient->updatePersona($personaId, $userId, $nickname, $avatarKey, $bio);
        return ApiResponse::success(PersonaPresenter::one($res['persona'] ?? []));
    }
}
