<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Client;

use Contracts\Gen\User\V1\FindOrCreateByPhoneRequest;
use Contracts\Gen\User\V1\FindOrCreateByPhoneResponse;
use Packages\GrpcCommon\UnaryGrpcClient;
use Services\AuthService\Domain\Client\UserDirectoryInterface;

final class GrpcUserDirectoryClient implements UserDirectoryInterface
{
    private ?UnaryGrpcClient $client = null;

    public function __construct(
        private readonly ?string $target = null,
        private readonly int $timeoutMs = 150
    ) {
    }

    public function findOrCreateByPhone(string $phone): array
    {
        $client = $this->client ??= new UnaryGrpcClient(
            $this->target ?: (getenv('GRPC_USER_SERVICE') ?: 'user-service:50052')
        );

        /** @var FindOrCreateByPhoneResponse $response */
        $response = $client->call(
            '/user.v1.UserService/FindOrCreateByPhone',
            new FindOrCreateByPhoneRequest(['phone' => $phone]),
            FindOrCreateByPhoneResponse::class,
            $this->timeoutMs
        );

        $user = $response->getUser();
        $userId = $user !== null ? (string)$user->getId() : '0';

        return [
            'user_id'     => $userId,
            'is_new_user' => $response->getIsNewUser(),
        ];
    }
}
