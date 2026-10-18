<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\Auth\V1\LogoutRequest;
use Packages\Database\EloquentConnection;
use Packages\IdGenerator\Snowflake;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Redis;
use RedisException;
use Services\AuthService\Application\Service\AuthApplicationService;
use Services\AuthService\Domain\Client\UserDirectoryInterface;
use Services\AuthService\Infrastructure\Auth\LoginCredentialVerifier;
use Services\AuthService\Infrastructure\Cache\RedisAccessTokenRevocationStore;
use Services\AuthService\Infrastructure\Repository\EloquentAuthRepository;
use Services\AuthService\Infrastructure\Token\JwtTokenCodec;
use Services\AuthService\Infrastructure\Token\WsTicketIssuer;
use Services\AuthService\Transport\Grpc\RoadRunnerAuthService;
use Spiral\RoadRunner\GRPC\Context;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\ResponseTrailers;
use Spiral\RoadRunner\GRPC\StatusCode;
use Tests\TestCase;

final class AuthLogoutRecoveryTest extends TestCase
{
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
        $pdo = new PDO('sqlite::memory:');
        try {
            // Exercise the production repository: revoked refresh rows must remain discoverable.
            $pdo->exec('CREATE TABLE devices (
                id TEXT PRIMARY KEY, user_id TEXT, device_uuid TEXT, platform TEXT,
                app_version TEXT, push_token TEXT, last_active_at TEXT, created_at TEXT, updated_at TEXT
            )');
            $pdo->exec('CREATE TABLE refresh_tokens (
                id TEXT PRIMARY KEY, user_id TEXT, device_id TEXT, token_hash TEXT UNIQUE,
                expires_at TEXT, revoked_at TEXT DEFAULT NULL, created_at TEXT
            )');
            $repository = new EloquentAuthRepository($pdo, new Snowflake());
            $device = $repository->findOrCreateDevice('1001', 'recovery-device', 'test', '1.0', null, null);
            $hash = hash('sha256', 'refresh');
            $repository->createRefreshToken('1001', $device->id, $hash, gmdate('Y-m-d H:i:s', time() + 300));

            $values = [];
            $writes = 0;
            $redis = $this->createMock(Redis::class);
            $redis->expects($this->exactly($failedWrite + 2))->method('setex')->willReturnCallback(
                static function (string $key, int $ttl, string $value) use (&$values, &$writes, $failedWrite, $disconnect): bool {
                    if (++$writes === $failedWrite) {
                        if ($disconnect) {
                            throw new RedisException('connection lost');
                        }
                        return false;
                    }
                    $values[$key] = $value;
                    return true;
                },
            );
            $redis->method('mGet')->willReturnCallback(
                static function (array $keys) use (&$values): array {
                    return array_map(static fn (string $key): string|false => $values[$key] ?? false, $keys);
                },
            );
            $users = $this->createMock(UserDirectoryInterface::class);
            $users->method('findById')->willReturn(['user_id' => '1001', 'status' => UserDirectoryInterface::STATUS_ACTIVE]);
            $codec = new JwtTokenCodec('test-secret-at-least-32-chars-long-12345');
            $application = new AuthApplicationService(
                authRepo: $repository,
                jwtCodec: $codec,
                wsTicketIssuer: new WsTicketIssuer('test-ws-secret-at-least-32-chars-12345', 60),
                credentialVerifier: new LoginCredentialVerifier('local', 'test-code', null, true),
                userDirectory: $users,
                accessTokenRevocationStore: new RedisAccessTokenRevocationStore($redis),
            );
            $token = $codec->encode([
                'sub' => '1001', 'device_id' => $device->id, 'iat' => time() - 1, 'exp' => time() + 300,
            ]);
            $this->assertTrue($application->verifyToken($token)['valid']);
            $service = new RoadRunnerAuthService($application, new NullLogger());
            $request = new LogoutRequest(['refresh_token' => 'refresh', 'access_token' => $token]);
            $trailers = new ResponseTrailers();
            $context = new Context(['metadata' => [], ResponseTrailers::class => $trailers]);
            try {
                $service->Logout($context, $request);
                $this->fail('The first logout must report the Redis failure');
            } catch (GRPCException $e) {
                $this->assertSame(StatusCode::UNAVAILABLE, $e->getCode());
                $this->assertSame((string)ErrorCode::SERVICE_UNAVAILABLE->value, $trailers->get('x-app-error-code', null));
            }

            $record = $repository->findRefreshToken($hash);
            $this->assertNotNull($record);
            $this->assertNotNull($record->revokedAt);
            $this->assertFalse($record->isValid());
            $this->assertFalse($repository->revokeRefreshToken($hash), 'The retry cannot rely on revoking the SQL row again');

            $retryContext = new Context(['metadata' => [], ResponseTrailers::class => new ResponseTrailers()]);
            $this->assertTrue($service->Logout($retryContext, $request)->getSuccess());
            $this->assertSame(['valid' => false], $application->verifyToken($token));
            $this->assertSame('1', $values['auth:access-token-revoked:' . hash('sha256', $token)]);
            $this->assertArrayHasKey('auth:access-device-revoked:' . $device->id, $values);
        } finally {
            EloquentConnection::forget($pdo);
        }
    }
}
