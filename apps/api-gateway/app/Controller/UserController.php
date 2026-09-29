<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Response\ApiResponse;
use Apps\ApiGateway\Response\PersonaPresenter;
use Contracts\Errors\ErrorCode;

final class UserController
{
    public function __construct(private readonly UserServiceClient $userClient)
    {
    }

    public function me(array $authContext): array
    {
        $userId = $authContext['user_id'] ?? '';
        if ($userId === '') {
            return ApiResponse::error(ErrorCode::AUTH_UNAUTHORIZED->value, 'unauthorized');
        }

        $userRes = $this->userClient->getUser($userId);
        $personasRes = $this->userClient->listPersonas($userId);

        return ApiResponse::success([
            'user'     => $userRes['user'] ?? null,
            'personas' => PersonaPresenter::many($personasRes['personas'] ?? []),
        ]);
    }
}
