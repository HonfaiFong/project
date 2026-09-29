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
use PDOException;
use ReflectionMethod;
use RuntimeException;
use Services\AuthService\Application\Service\AuthApplicationService;
use Services\AuthService\Domain\Client\UserDirectoryInterface;
use Services\AuthService\Domain\Entity\Device;
use Services\AuthService\Domain\Entity\RefreshToken;
use Services\AuthService\Domain\Repository\AuthRepositoryInterface;
use Services\AuthService\Infrastructure\Auth\LoginCredentialVerifier;
use Services\AuthService\Transport\Grpc\AuthGrpcHandler;
use Services\AuthService\Transport\Grpc\RoadRunnerAuthService;
use Services\UserService\Application\Service\UserApplicationService;
use Services\UserService\Domain\Entity\Persona;
use Services\UserService\Domain\Entity\User;
use Services\UserService\Domain\Repository\PersonaRepositoryInterface;
use Services\UserService\Domain\Repository\UserRepositoryInterface;
use Services\UserService\Transport\Grpc\UserGrpcService;
use Spiral\RoadRunner\GRPC\Context;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\ResponseTrailers;
use Spiral\RoadRunner\GRPC\StatusCode;
use Tests\TestCase;
use const Grpc\STATUS_INVALID_ARGUMENT;

/**
 * Contract tests for the `x-app-error-code` gRPC response trailer.
 *
 * The gateway client treats a present trailer as the authoritative business ErrorCode and skips
 * its gRPC transport-status fallback, so anything published in this trailer must be a real
 * ErrorCode -- never a gRPC status (INVALID_ARGUMENT = 3) and never an infrastructure exception
 * code (PDO 23000).
 */
final class GrpcErrorTrailerContractTest extends TestCase
{
    private const TRAILER_KEY = 'x-app-error-code';

    public function testInvalidArgumentGrpcExceptionDoesNotPublishGrpcStatusAsAppErrorCode(): void
    {
        [$service, $ctx, $trailers] = $this->userServiceFixture();

        try {
            // An empty user_id makes requireId() throw GRPCException(INVALID_ARGUMENT = 3).
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

    public function testUnimplementedGrpcExceptionDoesNotPublishGrpcStatusAsAppErrorCode(): void
    {
        [$service, $ctx, $trailers] = $this->userServiceFixture();

        try {
            $service->UpdateUser($ctx, new UpdateUserRequest());
            $this->fail('Expected GRPCException for the unimplemented UpdateUser call');
        } catch (GRPCException $e) {
            $this->assertSame(StatusCode::UNIMPLEMENTED, $e->getCode());
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
            // No user is registered, so UserApplicationService throws
            // RuntimeException('User not found', ErrorCode::USER_NOT_FOUND).
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
            // UNAUTHENTICATED = 16 is the transport status, not the business code.
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
            // Valid credentials so the failure happens later, in the user directory lookup.
            $service->Login($ctx, new LoginRequest(['phone' => '+8613800000000', 'code' => 'dev-code']));
            $this->fail('Expected GRPCException bubbling up the database failure');
        } catch (GRPCException $e) {
            $this->assertSame(ErrorCode::INTERNAL_SERVER_ERROR->message(), $e->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage());
        }

        // The regression guard: the PDO code 23000 must not be readable from the trailer.
        $this->assertNull(
            $trailers->get(self::TRAILER_KEY, null),
            'PDO exception codes must never be published as x-app-error-code'
        );
    }

    /**
     * Regression guard: an unknown non-zero handler code is still a FAILURE. It must be rejected
     * rather than falling through to the success response factory, which is what turned a
     * database outage into "gRPC OK" with empty tokens.
     *
     * Logout is used deliberately: its response factory tolerates a minimal payload, so a
     * fall-through surfaces as an unambiguous successful response instead of an unrelated
     * protobuf type error that would mask the bug.
     */
    public function testUnknownNonZeroHandlerCodeFailsInsteadOfReturningSuccess(): void
    {
        // The auth repository throws PDOException(23000); AuthGrpcHandler normalises it into a
        // handler result of ['code' => 23000], which is NOT a contract ErrorCode.
        $ctx = $this->context();
        $trailers = $this->trailers($ctx);
        $service = new RoadRunnerAuthService(new AuthGrpcHandler(
            $this->authService(failingAuthRepository: true),
            $this->logger()
        ));

        try {
            $response = $service->Logout($ctx, new LogoutRequest(['refresh_token' => 'whatever']));
            $this->fail(sprintf(
                'An unknown non-zero handler code must not be reported as gRPC OK (got success=%s)',
                var_export($response->getSuccess(), true)
            ));
        } catch (GRPCException $e) {
            // Pin the exact transport contract: a fault inside this service is INTERNAL(13),
            // not UNKNOWN(2). A loose assertion here would let the error chain drift again.
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

        // This is exactly what the client sees for a server-side GRPCException(INVALID_ARGUMENT):
        // no trailer -> appErrorCode is null -> the transport status decides the business code,
        // which is what turns the old x-app-error-code: 3 regression into HTTP 500.
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
            snowflake: new Snowflake(1)
        );

        $ctx = $this->context();
        $trailers = $this->trailers($ctx);

        return [new UserGrpcService($appService, $this->logger()), $ctx, $trailers];
    }

    /**
     * Drives the real AuthGrpcHandler + RoadRunnerAuthService pair over doubles, so the trailer
     * contract is asserted against the production error-mapping chain.
     *
     * @return array{0: RoadRunnerAuthService, 1: ContextInterface, 2: ResponseTrailers}
     */
    private function authFixture(bool $userDirectoryFails = false): array
    {
        $ctx = $this->context();
        $trailers = $this->trailers($ctx);

        return [
            new RoadRunnerAuthService(new AuthGrpcHandler(
                $this->authService(userDirectoryFails: $userDirectoryFails),
                $this->logger()
            )),
            $ctx,
            $trailers,
        ];
    }

    /**
     * @param bool $userDirectoryFails makes the credential check pass and the user lookup fail
     * @param bool $failingAuthRepository makes the local token store fail
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
            jwtSecret: 'test-secret-at-least-32-chars-long-12345',
            wsTicketSecret: 'test-ws-secret-at-least-32-chars-12345',
            credentialVerifier: new LoginCredentialVerifier('local', 'dev-code'),
            userDirectory: new class ($userDirectoryFails) implements UserDirectoryInterface {
                public function __construct(private readonly bool $fails)
                {
                }

                public function findOrCreateByPhone(string $phone): array
                {
                    if ($this->fails) {
                        throw new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062', 23000);
                    }

                    return ['user_id' => '20001', 'is_new_user' => false];
                }
            },
        );
    }

    /**
     * Mirrors the context Server::serve() builds for every RPC
     * (see spiral/roadrunner-grpc Server.php: ResponseHeaders/ResponseTrailers are context values).
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
 * Minimal non-persisting repository doubles: these contract tests only exercise the transport
 * error mapping, so the application service never gets past its first lookup.
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
        int $riskLevel = 0
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
        ?string $bio
    ): Persona {
        return new Persona(id: $id, userId: $userId, type: $type, nickname: $nickname, avatarKey: $avatarKey, bio: $bio);
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
 * Non-persisting auth repository double: the contract tests never reach token persistence.
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

    public function revokeUserTokens(string $userId): void
    {
    }
}

