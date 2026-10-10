<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Http\GatewayRequest;
use Apps\ApiGateway\Http\GatewayExceptionHandler;
use Apps\ApiGateway\Middleware\AuthMiddleware;
use Apps\ApiGateway\Middleware\SocialBindRateLimitMiddleware;
use Apps\ApiGateway\Middleware\SocialBindUserRateLimitMiddleware;
use Contracts\Errors\ErrorCode;
use Contracts\Gen\Auth\V1\VerifyTokenResponse;
use Packages\GrpcCommon\UnaryGrpcClient;
use Packages\Tracing\TraceContext;
use Psr\Log\NullLogger;
use Tests\TestCase;
use Webman\Http\Response;
use Webman\Route;
use Workerman\Connection\TcpConnection;

final class GatewaySocialBindRateLimitTest extends TestCase
{
    public function testIpRejectionStopsVerifyTokenAndDoesNotDoubleCountIpBuckets(): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->exactly(2))->method('call')
            ->with('/auth.v1.AuthService/VerifyToken', $this->anything(), VerifyTokenResponse::class, 300)
            ->willReturn(new VerifyTokenResponse(['valid' => true, 'user_id' => '42', 'device_id' => '7']));
        $keys = [];
        $counter = static function (string $key) use (&$keys): int {
            return $keys[$key] = ($keys[$key] ?? 0) + 1;
        };
        $chain = $this->chain($grpc, $counter, ipLimit: 2, userLimit: 10);
        for ($i = 0; $i < 2; $i++) {
            $this->assertSame(200, $chain($this->request())->getStatusCode());
        }
        $this->assertSame(2, $keys['auth:gateway:social-login:' . hash('sha256', '198.51.100.10')]);
        $this->assertSame(2, $keys['auth:gateway:social-login:bind:' . hash('sha256', '198.51.100.10')]);
        $this->assertSame(2, $keys['auth:gateway:social-login:device:' . hash('sha256', '198.51.100.10|device-1')]);
        $this->assertSame(2, $keys['auth:gateway:social-login:user:bind:' . hash('sha256', '42')]);
        for ($i = 0; $i < 3; $i++) {
            $this->assertRejected($chain, $this->request(), ErrorCode::RATE_LIMIT_EXCEEDED);
        }
    }

    public function testVerifiedUserLimitCannotBeEvadedByChangingIpOrBodyUserId(): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->exactly(3))->method('call')
            ->willReturn(new VerifyTokenResponse(['valid' => true, 'user_id' => '42', 'device_id' => '7']));
        $keys = [];
        $counter = static function (string $key) use (&$keys): int {
            return $keys[$key] = ($keys[$key] ?? 0) + 1;
        };
        $chain = $this->chain($grpc, $counter, ipLimit: 20, userLimit: 2);
        $this->assertSame(200, $chain($this->request('198.51.100.10'))->getStatusCode());
        $this->assertSame(200, $chain($this->request('198.51.100.11'))->getStatusCode());
        $this->assertRejected($chain, $this->request('198.51.100.12'), ErrorCode::RATE_LIMIT_EXCEEDED);
        $this->assertSame(3, $keys['auth:gateway:social-login:user:bind:' . hash('sha256', '42')]);
        $this->assertArrayNotHasKey('auth:gateway:social-login:user:bind:' . hash('sha256', '999'), $keys);
    }

    public function testInvalidTokenNeverConsumesAUserBucket(): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->once())->method('call')->willReturn(new VerifyTokenResponse(['valid' => false]));
        $keys = [];
        $counter = static function (string $key) use (&$keys): int {
            return $keys[$key] = ($keys[$key] ?? 0) + 1;
        };
        $chain = $this->chain($grpc, $counter, ipLimit: 20, userLimit: 2);
        $this->assertRejected($chain, $this->request(), ErrorCode::AUTH_TOKEN_INVALID);
        $this->assertCount(3, $keys);
    }

    /** @return \Closure(GatewayRequest): Response */
    private function chain(UnaryGrpcClient $grpc, \Closure $counter, int $ipLimit, int $userLimit): \Closure
    {
        static $classes = null;
        if ($classes === null) {
            Route::load([dirname(__DIR__, 2) . '/apps/api-gateway/config']);
            $route = Route::dispatch('POST', '/api/v1/auth/social/bind')[1]['route'];
            $classes = array_reverse($route->getMiddleware());
        }
        $this->assertSame([
            SocialBindRateLimitMiddleware::class,
            AuthMiddleware::class,
            SocialBindUserRateLimitMiddleware::class,
        ], $classes);
        $middlewares = [
            SocialBindRateLimitMiddleware::class => new SocialBindRateLimitMiddleware(
                counter: $counter, limit: $ipLimit, identityLimit: 0,
            ),
            AuthMiddleware::class => new AuthMiddleware(new AuthServiceClient(client: $grpc)),
            SocialBindUserRateLimitMiddleware::class => new SocialBindUserRateLimitMiddleware(
                counter: $counter, identityLimit: $userLimit,
            ),
        ];
        $handler = static fn (GatewayRequest $request): Response => new Response(200);
        foreach (array_reverse($classes) as $class) {
            $next = $handler;
            $middleware = $middlewares[$class];
            $handler = static fn (GatewayRequest $request): Response => $middleware->process($request, $next);
        }

        return $handler;
    }

    private function request(string $ip = '198.51.100.10'): GatewayRequest
    {
        TraceContext::setCurrent(TraceContext::create());
        $request = new GatewayRequest("POST /api/v1/auth/social/bind HTTP/1.1\r\nHost: localhost\r\n"
            . "Authorization: Bearer token\r\nContent-Type: application/json\r\n\r\n"
            . '{"provider":"wechat","code":"code","device":{"device_uuid":"device-1"},"user_id":"999"}');
        $connection = $this->getMockBuilder(TcpConnection::class)->disableOriginalConstructor()->onlyMethods(['getRemoteIp'])->getMock();
        $connection->method('getRemoteIp')->willReturn($ip);
        $request->connection = $connection;

        return $request;
    }

    private function assertRejected(callable $chain, GatewayRequest $request, ErrorCode $error): void
    {
        try {
            $chain($request);
            $this->fail('Expected request rejection');
        } catch (\RuntimeException $e) {
            $this->assertSame($error->value, $e->getCode());
            $response = (new GatewayExceptionHandler(new NullLogger(), false))->render($request, $e);
            $this->assertSame($error->httpStatus(), $response->getStatusCode());
        }
    }
}
