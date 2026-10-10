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
        // GRPC_USER_DIRECTORY_TIMEOUT_MS becomes the per-call deadline in
        // microseconds; zero or negative values would expire the call
        // immediately, so misconfiguration must fail loudly at construction.
        if ($this->timeoutMs < 1) {
            throw new InvalidArgumentException(
                'GRPC_USER_DIRECTORY_TIMEOUT_MS must resolve to a positive deadline in milliseconds'
            );
        }
    }

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
     * The configured budget is a ceiling, not an entitlement: when the gateway
     * propagated its deadline, only the remaining slice may be spent on this
     * hop so the outer deadline rules. An already-expired deadline fails fast
     * instead of firing a fresh full-budget network call.
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
