<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Client;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\User\V1\FindOrCreateByPhoneRequest;
use Contracts\Gen\User\V1\FindOrCreateByPhoneResponse;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use RuntimeException;
use Services\AuthService\Domain\Client\UserDirectoryInterface;
use const Grpc\STATUS_DEADLINE_EXCEEDED;
use const Grpc\STATUS_NOT_FOUND;

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

        try {
            /** @var FindOrCreateByPhoneResponse $response */
            $response = $client->call(
                '/user.v1.UserService/FindOrCreateByPhone',
                new FindOrCreateByPhoneRequest(['phone' => $phone]),
                FindOrCreateByPhoneResponse::class,
                $this->timeoutMs
            );
        } catch (GrpcCallException $e) {
            $code = $e->appErrorCode ?? match ($e->grpcStatus) {
                STATUS_DEADLINE_EXCEEDED => ErrorCode::RPC_TIMEOUT->value,
                STATUS_NOT_FOUND => ErrorCode::USER_NOT_FOUND->value,
                default => ErrorCode::SERVICE_UNAVAILABLE->value,
            };
            throw new RuntimeException($e->getMessage(), $code, $e);
        }

        $user = $response->getUser();
        $userId = $user !== null ? (string)$user->getId() : '0';

        return [
            'user_id'     => $userId,
            'is_new_user' => $response->getIsNewUser(),
        ];
    }
}
