<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Exception;
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

    /** @throws Exception */
    public function list(GatewayRequest $request): Response
    {
        $userId = $request->authContext['user_id'];
        if (empty($userId)) {
            return ApiResponse::error(ErrorCode::AUTH_UNAUTHORIZED->value, 'unauthorized');
        }

        $res = $this->userClient->listPersonas($userId);
        return ApiResponse::success([
            'personas' => PersonaPresenter::many($res['personas'] ?? []),
        ]);
    }

    /** @throws Exception */
    public function create(GatewayRequest $request): Response
    {
        $userId = $request->authContext['user_id'];
        if (empty($userId)) {
            return ApiResponse::error(ErrorCode::AUTH_UNAUTHORIZED->value, 'unauthorized');
        }

        $nickname = trim((string)$request->post('nickname', ''));
        if (blank($nickname)) {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'nickname is required');
        }

        $type = (int)$request->post('type', 1);
        // proto3 契约：'0' 等 falsy 值与缺失同义，故用 empty() 而非 filled()。
        $avatarKey = empty($request->post('avatar_key')) ? null : (string)$request->post('avatar_key');
        $bio = empty($request->post('bio')) ? null : (string)$request->post('bio');

        $res = $this->userClient->createPersona($userId, $type, $nickname, $avatarKey, $bio);

        return ApiResponse::success(PersonaPresenter::one($res['persona'] ?? []), 201);
    }

    /** @throws Exception */
    public function get(string $personaId): Response
    {
        if (empty($personaId)) {
            return ApiResponse::error(ErrorCode::INVALID_ARGUMENT->value, 'persona_id is required');
        }

        $res = $this->userClient->getPersona($personaId);
        return ApiResponse::success(PersonaPresenter::one($res['persona'] ?? []));
    }

    /** @throws Exception */
    public function update(GatewayRequest $request, string $personaId): Response
    {
        $userId = $request->authContext['user_id'];
        if (empty($userId)) {
            return ApiResponse::error(ErrorCode::AUTH_UNAUTHORIZED->value, 'unauthorized');
        }

        $nickname = $request->post('nickname') !== null ? (string)$request->post('nickname') : null;
        $avatarKey = $request->post('avatar_key') !== null ? (string)$request->post('avatar_key') : null;
        $bio = $request->post('bio') !== null ? (string)$request->post('bio') : null;

        $res = $this->userClient->updatePersona($personaId, $userId, $nickname, $avatarKey, $bio);
        return ApiResponse::success(PersonaPresenter::one($res['persona'] ?? []));
    }
}
