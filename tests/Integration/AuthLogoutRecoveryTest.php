<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apps\ApiGateway\Action\CreateWsTicketAction;
use Apps\ApiGateway\Controller\AuthController;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Http\GatewayExceptionHandler;
use Apps\ApiGateway\Http\GatewayRequest;
use Apps\ApiGateway\Middleware\AuthMiddleware;
use Contracts\Errors\ErrorCode;
use Contracts\Gen\Auth\V1\LogoutRequest;
use Contracts\Gen\Auth\V1\VerifyTokenRequest;
use DateTimeImmutable;
use DateTimeZone;
use Google\Protobuf\Internal\Message;
use Packages\Database\EloquentConnection;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use Packages\IdGenerator\Snowflake;
use Packages\Tracing\TraceContext;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Redis;
use RedisException;
use RuntimeException;
use Services\AuthService\Application\Service\AuthApplicationService;
use Services\AuthService\Domain\Client\UserDirectoryInterface;
use Services\AuthService\Domain\Repository\AuthRepositoryInterface;
use Services\AuthService\Infrastructure\Auth\LoginCredentialVerifier;
use Services\AuthService\Infrastructure\Cache\RedisAccessTokenRevocationStore;
use Services\AuthService\Infrastructure\Repository\EloquentAuthRepository;
use Services\AuthService\Infrastructure\Token\JwtTokenCodec;
use Services\AuthService\Infrastructure\Token\WsTicketIssuer;
use Services\AuthService\Transport\Grpc\RoadRunnerAuthService;
use Spiral\RoadRunner\GRPC\Context;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\ResponseTrailers;
use Tests\TestCase;
use Webman\Http\Response;

/** Real SQLite repository and gateway/auth business chain; Redis and gRPC transport are test doubles. */
final class AuthLogoutRecoveryTest extends TestCase
{
    private PDO $pdo;
    private EloquentAuthRepository $repository;
    private JwtTokenCodec $codec;
    private Redis&MockObject $redis;
    private string $deviceId;
    /** @var array<string, string> */
    private array $values = [];
    /** @var list<array{string, string, int}> */
    private array $deviceCommands = [];
    private int $writes = 0;
    private int $failedWrite = 0;
    private bool $disconnect = false;

    protected function setUp(): void
    {
        \Packages\Observability\MetricsRegistry::reset();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE devices (
            id TEXT PRIMARY KEY, user_id TEXT, device_uuid TEXT, platform TEXT,
            app_version TEXT, push_token TEXT, last_active_at TEXT, created_at TEXT, updated_at TEXT
        )');
        $this->pdo->exec('CREATE TABLE refresh_tokens (
            id TEXT PRIMARY KEY, user_id TEXT, device_id TEXT, token_hash TEXT UNIQUE,
            expires_at TEXT, revoked_at TEXT DEFAULT NULL, logged_out_at TEXT DEFAULT NULL, created_at TEXT
        )');
        $this->repository = new EloquentAuthRepository($this->pdo, new Snowflake());
        $this->deviceId = $this->repository->findOrCreateDevice('1001', 'recovery-device', 'test', '1.0', null, null)->id;
        $this->repository->createRefreshToken('1001', $this->deviceId, hash('sha256', 'refresh'), gmdate('Y-m-d H:i:s', time() + 300));
        $this->codec = new JwtTokenCodec('test-secret-at-least-32-chars-long-12345');
        $this->redis = $this->createMock(Redis::class);
        $write = function (string $key, int $ttl, string $value): bool {
            if (++$this->writes === $this->failedWrite) {
                if ($this->disconnect) {
                    throw new RedisException('connection lost');
                }
                return false;
            }
            $this->values[$key] = $value;
            return true;
        };
        $this->redis->method('setex')->willReturnCallback($write);
        // Lua itself is tested against a real Redis in RedisSocialAuthSupportTest.
        $this->redis->method('eval')->willReturnCallback(function (string $script, array $args, int $numKeys) use ($write): int|false {
            $this->assertSame(1, $numKeys);
            $this->deviceCommands[] = $args;
            [$key, $cutoff, $ttl] = $args;
            if (isset($this->values[$key]) && (float)$this->values[$key] >= (float)$cutoff) {
                return 1;
            }
            return $write($key, $ttl, $cutoff) ? 1 : false;
        });
        $this->redis->method('mGet')->willReturnCallback(fn (array $keys): array =>
            array_map(fn (string $key): string|false => $this->values[$key] ?? false, $keys));
    }

    protected function tearDown(): void
    {
        TraceContext::reset();
        EloquentConnection::forget($this->pdo);
    }

    /** @return array<string, array{int, bool}> */
    public static function writeFailures(): array
    {
        return [
            'device write rejected' => [1, false],
            'device write disconnected' => [1, true],
            'token write rejected' => [2, false],
            'token write disconnected' => [2, true],
        ];
    }

    #[DataProvider('writeFailures')]
    public function testLogoutCanBeRetriedAfterRedisRecovers(int $failedWrite, bool $disconnect): void
    {
        $this->failedWrite = $failedWrite;
        $this->disconnect = $disconnect;
        $app = $this->application();
        $token = $this->token('original');
        $first = $this->dispatch($app, 'refresh', $token);
        $this->assertSame(503, $first->getStatusCode());
        $this->assertSame(ErrorCode::SERVICE_UNAVAILABLE->value, json_decode($first->rawBody(), true)['code']);
        $record = $this->repository->findRefreshToken(hash('sha256', 'refresh'));
        $this->assertNotNull($record);
        $this->assertNotNull($record->revokedAt);
        $this->assertFalse($record->isValid());
        $this->assertFalse($this->repository->revokeRefreshToken(hash('sha256', 'refresh')));
        if ($failedWrite === 2) {
            $this->assertFalse($app->verifyToken($token)['valid'], 'Device revocation already succeeded before the 503');
        }

        $retry = $this->dispatch($app, 'refresh', $token);
        $this->assertSame(200, $retry->getStatusCode());
        $this->assertTrue(json_decode($retry->rawBody(), true)['data']['success']);
        $this->assertFalse($app->verifyToken($token)['valid']);
        $this->assertSame('1', $this->values['auth:access-token-revoked:' . hash('sha256', $token)]);
        $this->assertSame($this->deviceCommands[0][1], $this->deviceCommands[1][1], 'Retries must reuse the exact persisted cutoff');
    }

    /** @return array<string, array{string}> */
    public static function mismatchedPrincipals(): array
    {
        return ['other user' => ['1002'], 'same user, other device' => ['1001']];
    }

    #[DataProvider('mismatchedPrincipals')]
    public function testMismatchedBearerAndRefreshTokenIsRejected(string $userId): void
    {
        $other = $this->repository->findOrCreateDevice($userId, 'other-device', 'test', '1.0', null, null);
        $token = $this->codec->encode(['sub' => $userId, 'device_id' => $other->id, 'iat' => microtime(true), 'exp' => time() + 300]);
        $app = $this->application();
        $this->assertTrue($app->verifyToken($token)['valid']);
        $this->assertSame(401, $this->dispatch($app, 'refresh', $token)->getStatusCode());
        $this->assertNull($this->repository->findRefreshToken(hash('sha256', 'refresh'))?->revokedAt);
        $this->assertSame([], $this->values);
    }

    public function testReplayingRevokedRefreshTokenDoesNotRevokeNewerSessions(): void
    {
        $app = $this->application();
        $original = $this->token('original');
        $this->assertSame(200, $this->dispatch($app, 'refresh', $original)->getStatusCode());
        $cutoff = $this->values['auth:access-device-revoked:' . $this->deviceId];
        $login = $this->login($app);
        $this->assertTrue($app->verifyToken($login['access_token'])['valid']);
        $this->assertSame(200, $this->dispatch($app, 'refresh', $original)->getStatusCode());
        $this->assertSame($cutoff, $this->values['auth:access-device-revoked:' . $this->deviceId]);
        $this->assertTrue($app->verifyToken($login['access_token'])['valid']);
    }

    public function testOldRefreshCannotBlacklistBearerFromNewLogin(): void
    {
        $app = $this->application();
        $this->assertTrue($app->logout('refresh', $this->token('original')));
        $login = $this->login($app);
        $before = $this->values;
        $this->assertSame(401, $this->dispatch($app, 'refresh', $login['access_token'])->getStatusCode());
        $this->assertSame($before, $this->values);
        $this->assertTrue($app->verifyToken($login['access_token'])['valid']);
        $this->assertTrue($this->repository->findRefreshToken(hash('sha256', $login['refresh_token']))?->isValid());
    }

    public function testRecoveryRevokesOtherTokensIssuedBeforeLogoutInTheSameSecond(): void
    {
        $this->failedWrite = 1;
        $app = $this->application();
        $issuedAt = microtime(true);
        $original = $this->token('original', $issuedAt);
        $sibling = $this->token('sibling', $issuedAt);
        $this->assertSame(503, $this->dispatch($app, 'refresh', $original)->getStatusCode());
        $record = $this->repository->findRefreshToken(hash('sha256', 'refresh'));
        $this->assertNotNull($record?->revokedAt);
        $this->assertMatchesRegularExpression('/\.\d{6}$/', $record->revokedAt);
        $persisted = new DateTimeImmutable($record->revokedAt, new DateTimeZone('UTC'));
        $this->assertGreaterThanOrEqual($issuedAt, (float)$persisted->format('U.u'));
        $this->assertSame(200, $this->dispatch($app, 'refresh', $original)->getStatusCode());
        $this->assertFalse($app->verifyToken($sibling)['valid'], 'Recovery must revoke all older device tokens, including fractional iat values');
    }

    public function testConcurrentLogoutDoesNotAdvanceFirstCutoffPastNewLogin(): void
    {
        $repository = $this->createMock(AuthRepositoryInterface::class);
        foreach (['findRefreshToken', 'findOrCreateDevice', 'createRefreshToken', 'deviceBelongsToUser'] as $method) {
            $repository->method($method)->willReturnCallback(fn (...$args) => $this->repository->{$method}(...$args));
        }
        $entered = false;
        $app = $this->application($repository);
        $newLogin = null;
        $original = $this->token('original');
        $repository->method('revokeRefreshToken')->willReturnCallback(function (string $hash) use (&$entered, $app, &$newLogin, $original): bool {
            if (!$entered) {
                $entered = true;
                // A has read the active row. B commits logout and a new login before A's UPDATE resumes.
                $this->assertTrue($app->logout('refresh', $original));
                $newLogin = $this->login($app);
                $this->assertTrue($app->verifyToken($newLogin['access_token'])['valid']);
            }
            return $this->repository->revokeRefreshToken($hash);
        });
        $this->assertTrue($app->logout('refresh', $original));
        $this->assertTrue($app->verifyToken($newLogin['access_token'])['valid']);
        $this->assertSame($this->deviceCommands[0][1], $this->deviceCommands[1][1]);
    }

    public function testHistoricalRecoveryDoesNotExtendTheOriginalWindow(): void
    {
        $revokedAt = (new DateTimeImmutable('@' . (time() - 120)))->format('Y-m-d H:i:s.u');
        $this->pdo->prepare('UPDATE refresh_tokens SET revoked_at = ?, logged_out_at = ?')->execute([$revokedAt, $revokedAt]);
        $this->assertTrue($this->application()->logout('refresh'));
        $this->assertGreaterThanOrEqual(7078, $this->deviceCommands[0][2]);
        $this->assertLessThanOrEqual(7080, $this->deviceCommands[0][2]);
    }

    public function testExpiredRecoveryWindowDoesNotWriteRedis(): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - 7201);
        $this->pdo->prepare('UPDATE refresh_tokens SET revoked_at = ?, logged_out_at = ?')->execute([$cutoff, $cutoff]);
        $this->assertTrue($this->application()->logout('refresh'));
        $this->assertSame([], $this->values);
        $this->assertSame([], $this->deviceCommands);
    }

    public function testNonExistentRefreshTokenReturnsFalseAndDoesNotRevoke(): void
    {
        $app = $this->application();
        $token = $this->token('original');
        $this->assertFalse($app->logout('nonexistent', $token));
        $this->assertSame(401, $this->dispatch($app, 'nonexistent', $token)->getStatusCode());
        $this->assertTrue($app->verifyToken($token)['valid']);
        $this->assertSame([], $this->values);
    }

    public function testInvalidBearerCannotUseTheLogoutRecoveryException(): void
    {
        $this->assertSame(401, $this->dispatch($this->application(), 'refresh', 'invalid-jwt')->getStatusCode());
        $this->assertNull($this->repository->findRefreshToken(hash('sha256', 'refresh'))?->revokedAt);
        $this->assertSame([], $this->values);
        $this->assertMatchesRegularExpression('/auth_logout_recovery_total\{[^}\n]*status="401"[^}\n]*\} 1/',
            \Packages\Observability\MetricsRegistry::instance()->render(false));
    }

    public function testRecoveryMetricsDistinguishUnavailableAndRecoveredRequests(): void
    {
        $app = $this->application();
        $token = $this->token('original');
        $this->failedWrite = 2;
        $this->assertSame(503, $this->dispatch($app, 'refresh', $token)->getStatusCode());
        $this->failedWrite = 3;
        $this->assertSame(503, $this->dispatch($app, 'refresh', $token)->getStatusCode());
        $this->failedWrite = 0;
        $this->assertSame(200, $this->dispatch($app, 'refresh', $token)->getStatusCode());
        $metrics = \Packages\Observability\MetricsRegistry::instance()->render(false);
        foreach (['200', '503'] as $status) {
            $this->assertMatchesRegularExpression('/auth_logout_recovery_total\{[^}\n]*status="' . $status . '"[^}\n]*\} 1/', $metrics);
        }
    }

    public function testExpiredRefreshCannotLogOutANewerDeviceLogin(): void
    {
        $app = $this->application();
        $login = $this->login($app);
        $this->pdo->prepare('UPDATE refresh_tokens SET expires_at = ? WHERE token_hash = ?')
            ->execute([gmdate('Y-m-d H:i:s', time() - 1), hash('sha256', 'refresh')]);
        $this->assertSame(401, $this->dispatch($app, 'refresh', $this->token('old'))->getStatusCode());
        $this->assertTrue($this->repository->findRefreshToken(hash('sha256', $login['refresh_token']))->isValid());
        $this->assertTrue($app->verifyToken($login['access_token'])['valid']);
    }

    public function testLogoutRevokesEveryLoginOnTheDeviceAndPreservesOtherDevices(): void
    {
        $app = $this->application();
        $first = $this->login($app);
        $second = $this->login($app);
        $otherDevice = $this->repository->findOrCreateDevice('1001', 'other-device', 'test', '1', null, null);
        $this->repository->createRefreshToken('1001', $otherDevice->id, hash('sha256', 'other-refresh'), gmdate('Y-m-d H:i:s', time() + 300));

        $this->assertSame(200, $this->dispatch($app, $first['refresh_token'], $first['access_token'])->getStatusCode());
        foreach ([$first, $second] as $login) {
            $this->assertFalse($app->verifyToken($login['access_token'])['valid']);
            $record = $this->repository->findRefreshToken(hash('sha256', $login['refresh_token']));
            $this->assertNotNull($record?->loggedOutAt);
            $this->assertFalse($record->isValid());
        }
        $this->assertTrue($this->repository->findRefreshToken(hash('sha256', 'other-refresh'))->isValid());
        $this->expectExceptionCode(ErrorCode::AUTH_TOKEN_EXPIRED->value);
        $app->refreshToken($second['refresh_token']);
    }

    public function testRotatedCredentialIsRejectedInsteadOfReportingLogoutSuccess(): void
    {
        $app = $this->application();
        $login = $this->login($app);
        $rotated = $app->refreshToken($login['refresh_token']);
        $this->assertSame(401, $this->dispatch($app, $login['refresh_token'], $login['access_token'])->getStatusCode());
        $this->assertSame([], $this->deviceCommands);
        $this->assertNull($this->repository->findRefreshToken(hash('sha256', $login['refresh_token']))?->loggedOutAt);
        $this->assertTrue($app->verifyToken($rotated['access_token'])['valid']);
        $this->assertSame(200, $this->dispatch($app, $rotated['refresh_token'], $rotated['access_token'])->getStatusCode());
        $this->assertFalse($app->verifyToken($rotated['access_token'])['valid']);
    }

    public function testRefreshResponseCompletedAfterLogoutCannotRestoreAccess(): void
    {
        $repository = $this->createMock(AuthRepositoryInterface::class);
        foreach (['findRefreshToken', 'revokeRefreshToken', 'deviceBelongsToUser'] as $method) {
            $repository->method($method)->willReturnCallback(fn (...$args) => $this->repository->{$method}(...$args));
        }
        $app = $this->application($repository);
        $repository->method('rotateRefreshToken')->willReturnCallback(function (...$args) use ($app) {
            $record = $this->repository->rotateRefreshToken(...$args);
            // The replacement commits, then a sibling credential logs out before the JWT is encoded.
            $this->assertTrue($app->logout('sibling-refresh'));
            return $record;
        });
        $this->repository->createRefreshToken('1001', $this->deviceId, hash('sha256', 'sibling-refresh'), gmdate('Y-m-d H:i:s', time() + 300));
        $result = $app->refreshToken('refresh');
        $this->assertFalse($app->verifyToken($result['access_token'])['valid']);
        $this->assertFalse($this->repository->findRefreshToken(hash('sha256', $result['refresh_token']))->isValid());
    }

    #[DataProvider('loginLogoutCommitOrders')]
    public function testLoginAndLogoutKeepBothCredentialsInTheSameSession(bool $logoutFirst): void
    {
        $repository = $this->createMock(AuthRepositoryInterface::class);
        foreach (['findOrCreateDevice', 'findRefreshToken', 'revokeRefreshToken', 'deviceBelongsToUser'] as $method) {
            $repository->method($method)->willReturnCallback(fn (...$args) => $this->repository->{$method}(...$args));
        }
        $app = $this->application($repository);
        $issuedAt = null;
        $repository->method('createRefreshToken')->willReturnCallback(function (...$args) use ($app, $logoutFirst, &$issuedAt) {
            if ($logoutFirst) {
                // Login has started, but logout commits before it can acquire the device lock.
                $this->assertTrue($app->logout('refresh'));
            }
            $record = $this->repository->createRefreshToken(...$args);
            $issuedAt = (float)(new DateTimeImmutable($record->createdAt, new DateTimeZone('UTC')))->format('U.u');
            if (!$logoutFirst) {
                // Login commits first; logout completes before the login JWT is encoded/returned.
                $this->assertTrue($app->logout('refresh'));
            }
            return $record;
        });

        $login = $this->login($app);
        $this->assertSame($issuedAt, $this->codec->decode($login['access_token'])['iat']);
        $this->assertSame($logoutFirst, $app->verifyToken($login['access_token'])['valid']);
        $this->assertSame($logoutFirst, $this->repository->findRefreshToken(hash('sha256', $login['refresh_token']))->isValid());
    }

    /** @return array<string, array{bool}> */
    public static function loginLogoutCommitOrders(): array
    {
        return ['logout commits first' => [true], 'login commits first' => [false]];
    }

    public function testShorterIssuanceTtlRetainsHistoricalRevocationWindow(): void
    {
        $app = $this->application(accessTokenTtl: 900);
        $this->assertTrue($app->logout('refresh', $this->token('old-long-lived-token')));
        $this->assertGreaterThanOrEqual(7198, $this->deviceCommands[0][2]);
    }

    private function token(string $id, ?float $issuedAt = null): string
    {
        return $this->codec->encode(['jti' => $id, 'sub' => '1001', 'device_id' => $this->deviceId,
            'iat' => $issuedAt ?? microtime(true) - 1, 'exp' => time() + 300]);
    }

    private function application(?AuthRepositoryInterface $repository = null, int $accessTokenTtl = 7200): AuthApplicationService
    {
        $users = $this->createMock(UserDirectoryInterface::class);
        $users->method('findById')->willReturnCallback(static fn (string $id): array => ['user_id' => $id, 'status' => UserDirectoryInterface::STATUS_ACTIVE]);
        $users->method('findOrCreateByPhone')->willReturn(['user_id' => '1001', 'is_new_user' => false]);
        return new AuthApplicationService(
            authRepo: $repository ?? $this->repository,
            jwtCodec: $this->codec,
            wsTicketIssuer: new WsTicketIssuer('test-ws-secret-at-least-32-chars-12345', 60),
            credentialVerifier: new LoginCredentialVerifier('local', 'test-code', null, true),
            userDirectory: $users,
            accessTokenRevocationStore: new RedisAccessTokenRevocationStore($this->redis),
            phoneLoginEnabled: true,
            accessTokenTtl: $accessTokenTtl,
        );
    }

    /** @return array<string, mixed> */
    private function login(AuthApplicationService $app): array
    {
        return $app->login('13800138000', 'test-code', ['device_uuid' => 'recovery-device', 'platform' => 'test', 'app_version' => '1.0']);
    }

    private function dispatch(AuthApplicationService $app, string $refreshToken, string $bearer): Response
    {
        $service = new RoadRunnerAuthService($app, new NullLogger());
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->method('call')->willReturnCallback(static function (string $method, Message $request) use ($service): Message {
            $trailers = new ResponseTrailers();
            $context = new Context(['metadata' => [], ResponseTrailers::class => $trailers]);
            try {
                return match (true) {
                    $method === '/auth.v1.AuthService/VerifyToken' && $request instanceof VerifyTokenRequest => $service->VerifyToken($context, $request),
                    $method === '/auth.v1.AuthService/Logout' && $request instanceof LogoutRequest => $service->Logout($context, $request),
                    default => throw new \LogicException('Unexpected gRPC call: ' . $method),
                };
            } catch (GRPCException $e) {
                $code = $trailers->get('x-app-error-code', null);
                throw new GrpcCallException($e->getCode(), $code === null ? null : (int)$code, $e->getMessage());
            }
        });
        $client = new AuthServiceClient(client: $grpc);
        $controller = new AuthController($client, new CreateWsTicketAction($client, new UserServiceClient('127.0.0.1:1')), $this->validationFactory());
        $request = new GatewayRequest("POST /api/v1/auth/logout HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer {$bearer}\r\nContent-Type: application/json\r\n\r\n"
            . json_encode(['refresh_token' => $refreshToken]));
        try {
            return (new AuthMiddleware($client))->process($request, static fn (GatewayRequest $request): Response => $controller->logout($request));
        } catch (RuntimeException $e) {
            return (new GatewayExceptionHandler(new NullLogger(), false))->render($request, $e);
        }
    }
}
