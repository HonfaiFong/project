<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Grpc\UserServiceClient;
use Contracts\Errors\ErrorCode;
use Contracts\Gen\Auth\V1\LoginRequest;
use Contracts\Gen\Auth\V1\LogoutRequest;
use Contracts\Gen\Auth\V1\VerifyTokenRequest;
use Contracts\Gen\User\V1\GetUserRequest;
use Contracts\Gen\User\V1\UpdateUserRequest;
use Packages\GrpcCommon\GrpcCallException;
use Packages\IdGenerator\Snowflake;
use Packages\Logging\JsonLogger;
use Packages\Phone\PhoneNormalizer;
use PDOException;
use ReflectionMethod;
use RuntimeException;
use Services\AuthService\Application\Service\AuthApplicationService;
use Services\AuthService\Domain\Client\UserDirectoryInterface;
use Services\AuthService\Domain\Entity\Device;
use Services\AuthService\Domain\Entity\RefreshToken;
use Services\AuthService\Domain\Repository\AuthRepositoryInterface;
use Services\AuthService\Infrastructure\Auth\LoginCredentialVerifier;
use Services\AuthService\Infrastructure\Token\JwtTokenCodec;
use Services\AuthService\Infrastructure\Token\WsTicketIssuer;
use Services\AuthService\Transport\Grpc\RoadRunnerAuthService;
use Services\UserService\Application\Service\UserApplicationService;
use Services\UserService\Domain\Entity\Persona;
use Services\UserService\Domain\Entity\User;
use Services\UserService\Domain\Repository\PersonaRepositoryInterface;
use Services\UserService\Domain\Repository\UserRepositoryInterface;
use Services\UserService\Transport\Grpc\UserGrpcService;
use Spiral\RoadRunner\GRPC\Context;
use Tests\Support\PassThroughTransactionManager;
use Tests\Support\RecordingEventOutbox;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\ResponseTrailers;
use Spiral\RoadRunner\GRPC\StatusCode;
use Tests\TestCase;
use const Grpc\STATUS_INVALID_ARGUMENT;

/**
 * `x-app-error-code` gRPC 响应尾部元数据的协议测试。
 *
 * 网关客户端将存在的尾部元数据视为权威业务 ErrorCode，并跳过 gRPC 传输状态回退；因此其中发布的值必须是真实
 * ErrorCode，不能是 gRPC 状态（INVALID_ARGUMENT = 3）或基础设施异常代码（PDO 23000）。
 */
final class GrpcErrorTrailerContractTest extends TestCase
{
    private const TRAILER_KEY = 'x-app-error-code';

    public function testInvalidArgumentGrpcExceptionDoesNotPublishGrpcStatusAsAppErrorCode(): void
    {
        [$service, $ctx, $trailers] = $this->userServiceFixture();

        try {
            // 空 user_id 会使 requireId() 抛出 GRPCException(INVALID_ARGUMENT = 3)。
            $service->GetUser($ctx, new GetUserRequest());
            $this->fail('Expected GRPCException for an empty user_id');
        } catch (GRPCException $e) {
            $this->assertSame(StatusCode::INVALID_ARGUMENT, $e->getCode());
        }

        $this->assertNull(
            $trailers->get(self::TRAILER_KEY, null),
            'A gRPC transport status must never be published as x-app-error-code'
        );
    }

    public function testTransportGrpcExceptionDoesNotPublishGrpcStatusAsAppErrorCode(): void
    {
        $userRepo = new class extends EmptyUserRepository {
            public function findById(string $id): ?User
            {
                throw GRPCException::create('Transport status exception', StatusCode::UNIMPLEMENTED);
            }
        };

        $appService = new UserApplicationService(
            userRepo: $userRepo,
            personaRepo: new EmptyPersonaRepository(),
            snowflake: new Snowflake(1),
            transactionManager: new PassThroughTransactionManager(),
            outbox: new RecordingEventOutbox(),
            phoneNormalizer: new PhoneNormalizer(),
        );

        $ctx = $this->context();
        $trailers = $this->trailers($ctx);
        $service = new UserGrpcService($appService, $this->logger());

        try {
            $service->GetUser($ctx, new GetUserRequest(['user_id' => '1001']));
            $this->fail('Expected GRPCException for UNIMPLEMENTED call');
        } catch (GRPCException $e) {
            $this->assertSame(StatusCode::UNIMPLEMENTED, $e->getCode());
        }

        $this->assertNull($trailers->get(self::TRAILER_KEY, null));
    }

    public function testUpdateUserInvalidIdThrowsInvalidArgumentWithoutTrailer(): void
    {
        [$service, $ctx, $trailers] = $this->userServiceFixture();

        try {
            $service->UpdateUser($ctx, new UpdateUserRequest());
            $this->fail('Expected GRPCException for empty user_id');
        } catch (GRPCException $e) {
            $this->assertSame(StatusCode::INVALID_ARGUMENT, $e->getCode());
        }

        $this->assertNull($trailers->get(self::TRAILER_KEY, null));
    }

    public function testInfrastructureExceptionCodeDoesNotBecomeAppErrorCode(): void
    {
        [$service, $ctx, $trailers] = $this->userServiceFixture(failingUserRepository: true);

        try {
            $service->GetUser($ctx, new GetUserRequest(['user_id' => 42]));
            $this->fail('Expected GRPCException wrapping the repository failure');
        } catch (GRPCException $e) {
            $this->assertSame(StatusCode::INTERNAL, $e->getCode());
            $this->assertSame(ErrorCode::INTERNAL_SERVER_ERROR->message(), $e->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage());
        }

        $this->assertNull(
            $trailers->get(self::TRAILER_KEY, null),
            'PDO exception codes must never be published as x-app-error-code'
        );
    }

    public function testBusinessErrorCodeIsPublishedInTrailer(): void
    {
        [$service, $ctx, $trailers] = $this->userServiceFixture();

        try {
            // 未注册任何用户，因此 UserApplicationService 抛出 RuntimeException('User not found', ErrorCode::USER_NOT_FOUND)。
            $service->GetUser($ctx, new GetUserRequest(['user_id' => 4242]));
            $this->fail('Expected GRPCException for an unknown user');
        } catch (GRPCException $e) {
            $this->assertSame(StatusCode::NOT_FOUND, $e->getCode());
        }

        $this->assertSame(
            (string)ErrorCode::USER_NOT_FOUND->value,
            $trailers->get(self::TRAILER_KEY, null)
        );
    }

    public function testBusinessErrorIsPublishedInTrailer(): void
    {
        [$service, $ctx, $trailers] = $this->authFixture();

        try {
            $service->Login($ctx, new LoginRequest(['phone' => '+8613800000000', 'code' => 'wrong-code']));
            $this->fail('Expected GRPCException for rejected credentials');
        } catch (GRPCException $e) {
            // UNAUTHENTICATED = 16 是传输状态，不是业务代码。
            $this->assertSame(StatusCode::UNAUTHENTICATED, $e->getCode());
            $this->assertSame(ErrorCode::AUTH_INVALID_CREDENTIALS->message(), $e->getMessage());
        }

        $this->assertSame(
            (string)ErrorCode::AUTH_INVALID_CREDENTIALS->value,
            $trailers->get(self::TRAILER_KEY, null)
        );
    }

    public function testAuthServiceDoesNotPublishInfrastructureExceptionCodeAsAppErrorCode(): void
    {
        [$service, $ctx, $trailers] = $this->authFixture(userDirectoryFails: true);

        try {
            // 使用有效凭据，使失败发生在后续用户目录查询中。
            $service->Login($ctx, new LoginRequest(['phone' => '+8613800000000', 'code' => 'dev-code']));
            $this->fail('Expected GRPCException bubbling up the database failure');
        } catch (GRPCException $e) {
            $this->assertSame(ErrorCode::INTERNAL_SERVER_ERROR->message(), $e->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage());
        }

        // 回归检查：不能从尾部元数据读取到 PDO 代码 23000。
        $this->assertNull(
            $trailers->get(self::TRAILER_KEY, null),
            'PDO exception codes must never be published as x-app-error-code'
        );
    }

    /**
     * 回归检查：未知的非零处理器代码仍表示失败，须拒绝，不能继续进入成功响应工厂；此前该行为会把数据库故障转换为带空
     * 令牌的“gRPC OK”。
     *
     * 有意使用退出登录，因为其响应工厂允许最小载荷；错误放行会明确表现为成功响应，而不会被无关的 Protobuf 类型错误掩
     * 盖。
     */
    public function testUnknownNonZeroHandlerCodeFailsInsteadOfReturningSuccess(): void
    {
        // 认证仓储抛出 PDOException(23000)，它不是协议定义的 ErrorCode。
        $ctx = $this->context();
        $trailers = $this->trailers($ctx);
        $service = new RoadRunnerAuthService(
            $this->authService(failingAuthRepository: true),
            $this->logger()
        );

        try {
            $response = $service->Logout($ctx, new LogoutRequest(['refresh_token' => 'whatever']));
            $this->fail(sprintf(
                'An unknown non-zero handler code must not be reported as gRPC OK (got success=%s)',
                var_export($response->getSuccess(), true)
            ));
        } catch (GRPCException $e) {
            // 固定明确传输协议：服务内部故障应为 INTERNAL(13)，而非 UNKNOWN(2)；宽松断言会让错误链再次产生偏差。
            $this->assertSame(StatusCode::INTERNAL, $e->getCode());
            $this->assertSame(ErrorCode::INTERNAL_SERVER_ERROR->message(), $e->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage());
        }

        $this->assertNull(
            $trailers->get(self::TRAILER_KEY, null),
            'An unmapped code is not a contract ErrorCode, so it must not be published'
        );
    }

    public function testClientWithoutTrailerFallsBackToTransportStatusMapping(): void
    {
        $client = new UserServiceClient('127.0.0.1:1', 150);
        $ref = new ReflectionMethod($client, 'mapGrpcCallException');

        // 客户端收到服务端 GRPCException(INVALID_ARGUMENT) 时的实际情况：缺少尾部元数据 -> appErrorCode 为 null -> 由传
        // 输状态决定业务代码；此前 x-app-error-code: 3 的错误会因此转换为 HTTP 500。
        $ex = new GrpcCallException(STATUS_INVALID_ARGUMENT, null, 'user_id is required');
        /** @var RuntimeException $mapped */
        $mapped = $ref->invoke($client, $ex);

        $this->assertSame(ErrorCode::INVALID_ARGUMENT->value, $mapped->getCode());
    }

    /**
     * @return array{0: UserGrpcService, 1: ContextInterface, 2: ResponseTrailers}
     */
    private function userServiceFixture(bool $failingUserRepository = false): array
    {
        $userRepo = $failingUserRepository
            ? new class extends EmptyUserRepository {
                public function findById(string $id): ?User
                {
                    throw new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062', 23000);
                }
            }
            : new EmptyUserRepository();

        $appService = new UserApplicationService(
            userRepo: $userRepo,
            personaRepo: new EmptyPersonaRepository(),
            snowflake: new Snowflake(1),
            transactionManager: new PassThroughTransactionManager(),
            outbox: new RecordingEventOutbox(),
            phoneNormalizer: new PhoneNormalizer(),
        );

        $ctx = $this->context();
        $trailers = $this->trailers($ctx);

        return [new UserGrpcService($appService, $this->logger()), $ctx, $trailers];
    }

    /**
     * 使用测试替身直接驱动 RoadRunnerAuthService，针对生产错误映射链断言尾部元数据协议。
     *
     * @return array{0: RoadRunnerAuthService, 1: ContextInterface, 2: ResponseTrailers}
     */
    private function authFixture(bool $userDirectoryFails = false): array
    {
        $ctx = $this->context();
        $trailers = $this->trailers($ctx);

        return [
            new RoadRunnerAuthService(
                $this->authService(userDirectoryFails: $userDirectoryFails),
                $this->logger()
            ),
            $ctx,
            $trailers,
        ];
    }

    /**
     * @param bool $userDirectoryFails 使凭据检查通过、用户查询失败
     * @param bool $failingAuthRepository 使本地令牌存储失败
     */
    private function authService(
        bool $userDirectoryFails = false,
        bool $failingAuthRepository = false
    ): AuthApplicationService {
        return new AuthApplicationService(
            authRepo: $failingAuthRepository
                ? new class extends EmptyAuthRepository {
                    public function findRefreshToken(string $tokenHash): ?RefreshToken
                    {
                        throw new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062', 23000);
                    }
                }
                : new EmptyAuthRepository(),
            jwtCodec: new JwtTokenCodec('test-secret-at-least-32-chars-long-12345'),
            wsTicketIssuer: new WsTicketIssuer('test-ws-secret-at-least-32-chars-12345', 60),
            credentialVerifier: new LoginCredentialVerifier('local', 'dev-code', null, true),
            phoneLoginEnabled: true,
            userDirectory: new class ($userDirectoryFails) implements UserDirectoryInterface {
                public function __construct(private readonly bool $fails)
                {
                }

                public function findOrCreateByPhone(string $phone, string $registerChannel = 'default'): array
                {
                    if ($this->fails) {
                        throw new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062', 23000);
                    }

                    return ['user_id' => '20001', 'is_new_user' => false];
                }

                public function findOrCreateBySocialIdentity(string $registrationKey, string $registerChannel): array
    {
        return ['user_id' => '20001', 'is_new_user' => false];
    }

    public function findById(string $userId): array
                {
                    return ['user_id' => $userId, 'status' => self::STATUS_ACTIVE];
                }
            },
        );
    }

    /**
     * 沿用 Server::serve() 为每个 RPC 构建的上下文（参见 spiral/roadrunner-grpc Server.php：
     * ResponseHeaders/ResponseTrailers 均为上下文值）。
     */
    private function context(): Context
    {
        return new Context([
            'metadata'               => [],
            ResponseTrailers::class => new ResponseTrailers(),
        ]);
    }

    private function trailers(ContextInterface $ctx): ResponseTrailers
    {
        $trailers = $ctx->getValue(ResponseTrailers::class);
        $this->assertInstanceOf(ResponseTrailers::class, $trailers);

        return $trailers;
    }

    private function logger(): JsonLogger
    {
        return new JsonLogger('grpc-trailer-contract-test', 'test', fopen('php://memory', 'rb+'));
    }
}

/**
 * 不执行持久化的最小仓储替身：协议测试仅验证传输错误映射，应用服务不会越过首次查询。
 */
class EmptyUserRepository implements UserRepositoryInterface
{
    public function findById(string $id): ?User
    {
        return null;
    }

    public function findByIdForUpdate(string $id): ?User
    {
        return null;
    }

    public function findByPhone(string $phone): ?User
    {
        return null;
    }

    public function findBySocialRegistrationKey(string $registrationKey): ?User
    {
        return null;
    }

    public function socialRegistrationKeyExists(string $registrationKey): bool
    {
        return false;
    }

    public function findByEmail(string $email): ?User
    {
        return null;
    }

    public function batchFindByIds(array $ids): array
    {
        return [];
    }

    public function create(
        string $id,
        ?string $phone,
        ?string $email,
        string $registerChannel = 'default',
        int $riskLevel = 0,
        ?string $socialRegistrationKey = null
    ): User {
        return new User(id: $id, status: 1, phone: $phone, email: $email, registerChannel: $registerChannel, riskLevel: $riskLevel);
    }

    public function update(string $id, ?string $phone, ?string $email): ?User
    {
        return null;
    }

    public function updateLastLogin(string $id): void
    {
    }
}

class EmptyPersonaRepository implements PersonaRepositoryInterface
{
    public function findById(string $id): ?Persona
    {
        return null;
    }

    public function batchFindByIds(array $ids): array
    {
        return [];
    }

    public function findByUserId(string $userId): array
    {
        return [];
    }

    public function findInternalAliasByUserIdForUpdate(string $userId): ?Persona
    {
        return null;
    }

    public function countByUserId(string $userId): int
    {
        return 0;
    }

    public function create(
        string $id,
        string $userId,
        int $type,
        string $nickname,
        ?string $avatarKey,
        ?string $bio,
        bool $isInternalAlias = false
    ): Persona {
        return new Persona(
            id: $id,
            userId: $userId,
            type: $type,
            nickname: $nickname,
            avatarKey: $avatarKey,
            bio: $bio,
            isInternalAlias: $isInternalAlias,
        );
    }

    public function update(
        string $id,
        string $userId,
        ?string $nickname,
        ?string $avatarKey,
        ?string $bio
    ): ?Persona {
        return null;
    }
}

/**
 * 不执行持久化的认证仓储替身；协议测试不会进入令牌持久化步骤。
 */
class EmptyAuthRepository implements AuthRepositoryInterface
{
    public function findOrCreateDevice(
        string $userId,
        string $deviceUuid,
        string $platform,
        string $appVersion,
        ?string $pushToken,
        ?string $ip
    ): Device {
        return new Device(
            id: 'dev_1',
            userId: $userId,
            deviceUuid: $deviceUuid,
            platform: $platform,
            appVersion: $appVersion,
            pushToken: $pushToken,
            lastIp: $ip
        );
    }

    public function deviceBelongsToUser(string $userId, string $deviceId): bool
    {
        return false;
    }

    public function createRefreshToken(
        string $userId,
        string $deviceId,
        string $tokenHash,
        string $expiresAt
    ): RefreshToken {
        return new RefreshToken(
            id: 'tok_1',
            userId: $userId,
            deviceId: $deviceId,
            tokenHash: $tokenHash,
            expiresAt: $expiresAt
        );
    }

    public function rotateRefreshToken(
        string $tokenHash,
        string $newTokenHash,
        string $newExpiresAt
    ): ?RefreshToken {
        return null;
    }

    public function findRefreshToken(string $tokenHash): ?RefreshToken
    {
        return null;
    }

    public function revokeRefreshToken(string $tokenHash): bool
    {
        return false;
    }
}
