<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Http\GatewayExceptionHandler;
use Apps\ApiGateway\Http\GatewayRequest;
use Apps\ApiGateway\Middleware\AuthMiddleware;
use Contracts\Errors\ErrorCode;
use Contracts\Gen\Auth\V1\VerifyTokenRequest;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use Packages\IdGenerator\Snowflake;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Redis;
use RedisException;
use RuntimeException;
use Services\AuthService\Application\Service\AuthApplicationService;
use Services\AuthService\Domain\Client\UserDirectoryInterface;
use Services\AuthService\Domain\Entity\RefreshToken;
use Services\AuthService\Domain\Repository\AccessTokenRevocationStoreInterface;
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

final class VerifyTokenAvailabilityTest extends TestCase
{
    /** @return array<string, array{string, ErrorCode}> */
    public static function failures(): array
    {
        return [
            'Redis command failed' => ['redis-false', ErrorCode::SERVICE_UNAVAILABLE],
            'Redis disconnected' => ['redis', ErrorCode::SERVICE_UNAVAILABLE],
            'Auth database unavailable' => ['mysql', ErrorCode::SERVICE_UNAVAILABLE],
            'UserService unavailable' => ['user', ErrorCode::SERVICE_UNAVAILABLE],
            'UserService deadline exceeded' => ['timeout', ErrorCode::RPC_TIMEOUT],
            'User banned' => ['banned', ErrorCode::AUTH_TOKEN_INVALID],
            'User deleted' => ['deleted', ErrorCode::AUTH_TOKEN_INVALID],
            'Invalid JWT' => ['jwt', ErrorCode::AUTH_TOKEN_INVALID],
        ];
    }

    public function testMissingRedisRevocationsAreDifferentFromCommandFailure(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('mGet')->willReturnOnConsecutiveCalls([false, false], [false, '100'], ['1', false]);
        $store = new RedisAccessTokenRevocationStore($redis);

        $this->assertFalse($store->isRevoked('hash', 'device', 99));
        $this->assertTrue($store->isRevoked('hash', 'device', 99));
        $this->assertTrue($store->isRevoked('hash', 'device', 101));
    }

    public function testDeviceRevocationPreservesFractionalSecondsAndLegacyCutoffs(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('mGet')->willReturnOnConsecutiveCalls(
            [false, '100.500000'], [false, '100.500000'], [false, '100.500000'],
            [false, '100'], [false, '100'], ['1', '100.500000'],
        );
        $store = new RedisAccessTokenRevocationStore($redis);
        $this->assertTrue($store->isRevoked('hash', 'device', 100.4));
        $this->assertTrue($store->isRevoked('hash', 'device', 100.5));
        $this->assertFalse($store->isRevoked('hash', 'device', 100.6));
        $this->assertTrue($store->isRevoked('hash', 'device', 100));
        $this->assertFalse($store->isRevoked('hash', 'device', 100.1));
        $this->assertTrue($store->isRevoked('hash', 'device', 100.6));
    }

    public function testDeviceRevocationStoresSubsecondCutoff(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->never())->method('get');
        $redis->expects($this->never())->method('setex');
        $redis->expects($this->once())->method('eval')->with(
            $this->isType('string'),
            $this->callback(static fn (array $args): bool => $args[0] === 'auth:access-device-revoked:device'
                && preg_match('/^\d+\.\d{6}$/', $args[1]) === 1 && $args[2] === 60),
            1,
        )->willReturn(1);
        (new RedisAccessTokenRevocationStore($redis))->revokeDevice('device', 60);
    }

    /**
     * 撤销写入失败必须失败关闭：注销不能报告成功，否则旧访问令牌会继续被接受。
     */
    public function testRevocationWriteFailureIsNotSilentlyIgnored(): void
    {
        foreach (['revokeToken', 'revokeDevice'] as $method) {
            $redis = $this->createMock(Redis::class);
            $redis->expects($this->once())->method($method === 'revokeDevice' ? 'eval' : 'setex')->willReturn(false);

            try {
                (new RedisAccessTokenRevocationStore($redis))->{$method}('value', 60);
                $this->fail($method . ' must not report success when Redis rejects the write');
            } catch (RuntimeException $e) {
                $this->assertSame(ErrorCode::SERVICE_UNAVAILABLE->value, $e->getCode());
                $this->assertSame(ErrorCode::SERVICE_UNAVAILABLE->message(), $e->getMessage());
            }
        }
    }

    /** Redis 抛出的传输异常同样不能退化成功。 */
    public function testRevocationTransportFailurePropagatesAsServiceUnavailable(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('eval')->willThrowException(new RedisException('connection lost'));

        try {
            (new RedisAccessTokenRevocationStore($redis))->revokeDevice('device', 60);
            $this->fail('A Redis transport error must not be swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame(ErrorCode::SERVICE_UNAVAILABLE->value, $e->getCode());
            $this->assertInstanceOf(RedisException::class, $e->getPrevious());
        }
    }

    /** 端点 logout() 不得在撤销写入失败后仍返回成功。 */
    public function testLogoutFailsWhenTheDeviceRevocationWriteFails(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('eval')->willReturn(false);
        $repository = $this->createMock(AuthRepositoryInterface::class);
        $repository->method('findRefreshToken')->willReturn(new RefreshToken(
            id: 'rt-1',
            userId: '1001',
            deviceId: '2001',
            tokenHash: hash('sha256', 'refresh'),
            expiresAt: gmdate('Y-m-d H:i:s', time() + 300),
            revokedAt: gmdate('Y-m-d H:i:s', time() - 1),
            loggedOutAt: gmdate('Y-m-d H:i:s', time() - 1),
        ));
        $repository->method('revokeRefreshToken')->willReturn(true);
        $application = new AuthApplicationService(
            authRepo: $repository,
            jwtCodec: new JwtTokenCodec('test-secret-at-least-32-chars-long-12345'),
            wsTicketIssuer: new WsTicketIssuer('test-ws-secret-at-least-32-chars-12345', 60),
            credentialVerifier: new LoginCredentialVerifier('local', 'test-code', null, true),
            userDirectory: $this->createMock(UserDirectoryInterface::class),
            accessTokenRevocationStore: new RedisAccessTokenRevocationStore($redis),
        );

        try {
            $application->logout('refresh');
            $this->fail('logout() must not report success when the revocation marker was not stored');
        } catch (RuntimeException $e) {
            $this->assertSame(ErrorCode::SERVICE_UNAVAILABLE->value, $e->getCode());
        }
    }

    #[DataProvider('failures')]
    public function testVerificationFailureReachesGatewayWithCorrectHttpStatus(string $failure, ErrorCode $expected): void
    {
        $repository = $this->createMock(AuthRepositoryInterface::class);
        $repository->method('deviceBelongsToUser')->willReturn(true);
        $revocations = $this->createMock(AccessTokenRevocationStoreInterface::class);
        $revocations->method('isRevoked')->willReturn(false);
        $users = $this->createMock(UserDirectoryInterface::class);

        if ($failure === 'mysql') {
            $pdo = $this->createMock(PDO::class);
            $pdo->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn('mysql');
            $pdo->method('prepare')->willThrowException(new PDOException('database connection lost', 2006));
            $repository = new EloquentAuthRepository($pdo, new Snowflake());
        } elseif (in_array($failure, ['redis', 'redis-false'], true)) {
            $redis = $this->createMock(Redis::class);
            if ($failure === 'redis') {
                $redis->method('mGet')->willThrowException(new RedisException('connection lost'));
            } else {
                $redis->method('mGet')->willReturn(false);
            }
            $revocations = new RedisAccessTokenRevocationStore($redis);
        }

        if (in_array($failure, ['user', 'timeout', 'deleted'], true)) {
            $error = match ($failure) {
                'timeout' => ErrorCode::RPC_TIMEOUT,
                'deleted' => ErrorCode::USER_NOT_FOUND,
                default => ErrorCode::SERVICE_UNAVAILABLE,
            };
            $users->method('findById')->willThrowException(new RuntimeException($error->message(), $error->value));
        } else {
            $users->method('findById')->willReturn(['user_id' => '1001', 'status' => $failure === 'banned' ? 0 : 1]);
        }

        $codec = new JwtTokenCodec('test-secret-at-least-32-chars-long-12345');
        $application = new AuthApplicationService(
            authRepo: $repository,
            jwtCodec: $codec,
            wsTicketIssuer: new WsTicketIssuer('test-ws-secret-at-least-32-chars-12345', 60),
            credentialVerifier: new LoginCredentialVerifier('local', 'test-code', null, true),
            userDirectory: $users,
            accessTokenRevocationStore: $revocations,
        );
        $service = new RoadRunnerAuthService($application, new NullLogger());
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->once())->method('call')->willReturnCallback(
            static function (string $method, Message $request) use ($service): Message {
                $trailers = new ResponseTrailers();
                $context = new Context(['metadata' => [], ResponseTrailers::class => $trailers]);
                if (!$request instanceof VerifyTokenRequest) {
                    throw new \LogicException('Expected VerifyTokenRequest');
                }
                try {
                    return $service->VerifyToken($context, $request);
                } catch (GRPCException $e) {
                    $code = $trailers->get('x-app-error-code', null);
                    throw new GrpcCallException($e->getCode(), $code === null ? null : (int)$code, $e->getMessage());
                }
            }
        );
        $middleware = new AuthMiddleware(new AuthServiceClient(client: $grpc));
        $token = $failure === 'jwt' ? 'malformed' : $codec->encode([
            'sub' => '1001', 'device_id' => '2001', 'iat' => time(), 'exp' => time() + 300,
        ]);
        $request = new GatewayRequest("GET /api/v1/users/me HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer {$token}\r\n\r\n");
        $called = false;
        try {
            $middleware->process($request, static function () use (&$called): Response {
                $called = true;
                return new Response(200);
            });
            $this->fail('Verification must reject access');
        } catch (RuntimeException $e) {
            $response = (new GatewayExceptionHandler(new NullLogger(), false))->render($request, $e);
        }

        $this->assertFalse($called);
        $this->assertSame($expected->httpStatus(), $response->getStatusCode());
        $body = json_decode($response->rawBody(), true);
        $this->assertSame($expected->value, $body['code']);
        $this->assertStringNotContainsString('connection lost', $response->rawBody());
    }
}
