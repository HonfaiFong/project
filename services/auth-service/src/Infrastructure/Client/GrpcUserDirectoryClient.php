<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Client;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\User\V1\FindOrCreateByPhoneRequest;
use Contracts\Gen\User\V1\FindOrCreateByPhoneResponse;
use Contracts\Gen\User\V1\FindOrCreateBySocialIdentityRequest;
use Contracts\Gen\User\V1\FindOrCreateBySocialIdentityResponse;
use Contracts\Gen\User\V1\GetUserRequest;
use Contracts\Gen\User\V1\GetUserResponse;
use Packages\GrpcCommon\DeadlineContext;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use Services\AuthService\Domain\Client\UserDirectoryInterface;
use const Grpc\STATUS_DEADLINE_EXCEEDED;
use const Grpc\STATUS_NOT_FOUND;

final class GrpcUserDirectoryClient implements UserDirectoryInterface
{
    private ?UnaryGrpcClient $client = null;

    public function __construct(
        private readonly ?string $target = null,
        private readonly int $timeoutMs = 150,
        private readonly ?string $serviceToken = null,
    ) {
        // GRPC_USER_DIRECTORY_TIMEOUT_MS 会转换为微秒级单次调用截止时间；零或负值会使调用立即过期，因此错误配置须在构造
        // 时明确报错。
        if ($this->timeoutMs < 1) {
            throw new InvalidArgumentException(
                'GRPC_USER_DIRECTORY_TIMEOUT_MS must resolve to a positive deadline in milliseconds'
            );
        }
    }

    /**
     * @throws Exception gRPC 传输失败且未进入已映射状态路径时抛出
     */
    public function findOrCreateByPhone(string $phone, string $registerChannel = 'default'): array
    {
        $client = $this->client ??= new UnaryGrpcClient(
            $this->target ?: (getenv('GRPC_USER_SERVICE') ?: 'user-service:50052'),
            $this->serviceToken,
        );

        try {
            /** @var FindOrCreateByPhoneResponse $response */
            $response = $client->call(
                '/user.v1.UserService/FindOrCreateByPhone',
                new FindOrCreateByPhoneRequest([
                    'phone'           => $phone,
                    'register_channel' => $registerChannel,
                ]),
                FindOrCreateByPhoneResponse::class,
                $this->hopTimeoutMs()
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

    /**
     * @throws Exception gRPC 传输失败且未进入已映射状态路径时抛出
     */
    public function findOrCreateBySocialIdentity(string $registrationKey, string $registerChannel): array
    {
        $client = $this->client ??= new UnaryGrpcClient(
            $this->target ?: (getenv('GRPC_USER_SERVICE') ?: 'user-service:50052'),
            $this->serviceToken,
        );

        try {
            /** @var FindOrCreateBySocialIdentityResponse $response */
            $response = $client->call(
                '/user.v1.UserService/FindOrCreateBySocialIdentity',
                new FindOrCreateBySocialIdentityRequest([
                    'registration_key' => $registrationKey,
                    'register_channel' => $registerChannel,
                ]),
                FindOrCreateBySocialIdentityResponse::class,
                $this->hopTimeoutMs()
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

    /**
     * @return array{user_id: string, status: int}
     * @throws Exception gRPC 传输失败且未进入已映射状态路径时抛出
     */
    public function findById(string $userId): array
    {
        $client = $this->client ??= new UnaryGrpcClient(
            $this->target ?: (getenv('GRPC_USER_SERVICE') ?: 'user-service:50052'),
            $this->serviceToken,
        );

        try {
            /** @var GetUserResponse $response */
            $response = $client->call(
                '/user.v1.UserService/GetUser',
                new GetUserRequest(['user_id' => $userId]),
                GetUserResponse::class,
                $this->hopTimeoutMs()
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
        if ($user === null) {
            throw new RuntimeException(
                ErrorCode::USER_NOT_FOUND->message(),
                ErrorCode::USER_NOT_FOUND->value
            );
        }

        return [
            'user_id' => (string)$user->getId(),
            'status'  => $user->getStatus(),
        ];
    }

    /**
     * 配置预算仅是上限；网关传递截止时间后，此跳只能消耗剩余时间，确保外层截止时间起决定作用。已过截止时间须立即失败，
     * 不能再发起使用完整新预算的网络调用。
     */
    private function hopTimeoutMs(): int
    {
        $timeoutMs = DeadlineContext::clampHopBudgetMs($this->timeoutMs);
        if ($timeoutMs === null) {
            throw new RuntimeException(
                ErrorCode::RPC_TIMEOUT->message(),
                ErrorCode::RPC_TIMEOUT->value
            );
        }

        return $timeoutMs;
    }
}
