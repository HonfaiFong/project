<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Grpc\AuthServiceClient;
use Contracts\Gen\Auth\V1\BindSocialPhoneResponse;
use Contracts\Gen\Auth\V1\SendSocialPhoneCodeResponse;
use Contracts\Gen\Auth\V1\SocialLoginResponse;
use Contracts\Gen\Auth\V1\VerifyTokenResponse;
use Contracts\Errors\ErrorCode;
use InvalidArgumentException;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use Tests\TestCase;
use const Grpc\STATUS_DEADLINE_EXCEEDED;

final class AuthServiceClientTimeoutTest extends TestCase
{
    public function testUnavailableTransportWithoutTrailerReturnsSafeHttp503(): void
    {
        foreach ([\Grpc\STATUS_UNAVAILABLE, STATUS_DEADLINE_EXCEEDED] as $status) {
            $grpc = $this->createMock(UnaryGrpcClient::class);
            $grpc->method('call')->willThrowException(new GrpcCallException($status, null, 'private backend:50051 connection failed'));
            $client = new AuthServiceClient(client: $grpc);
            try {
                $client->verifyToken('token');
                $this->fail('Expected dependency failure');
            } catch (\RuntimeException $e) {
                $expected = $status === STATUS_DEADLINE_EXCEEDED ? ErrorCode::RPC_TIMEOUT : ErrorCode::SERVICE_UNAVAILABLE;
                $this->assertSame($expected->value, $e->getCode());
                $request = new \Apps\ApiGateway\Http\GatewayRequest("GET /api/v1/me HTTP/1.1\r\nHost: localhost\r\n\r\n");
                $handler = new \Apps\ApiGateway\Http\GatewayExceptionHandler(new \Psr\Log\NullLogger(), false);
                $response = $handler->render($request, $e);
                $this->assertSame(503, $response->getStatusCode());
                $this->assertSame($expected->message(), json_decode($response->rawBody(), true)['message']);
                $this->assertStringNotContainsString('private backend', $response->rawBody());
            }
        }
    }

    public function testSocialLoginDeadlineCannotBeConfiguredAtOrBelowTheHttpTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuthServiceClient(
            socialLoginTimeoutMs: 6000,
            socialHttpTimeoutMs: 3000,
        );
    }

    public function testSocialRpcDeadlinesRemainLongerThanTheOAuthHttpTimeout(): void
    {
        $timeouts = [];
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->exactly(4))
            ->method('call')
            ->willReturnCallback(function (string $method, mixed $request, string $responseClass, int $timeoutMs) use (&$timeouts): object {
                $timeouts[$method] = $timeoutMs;

                if ($method === '/auth.v1.AuthService/SocialLogin') {
                    usleep(500_000);
                    return new SocialLoginResponse([
                        'state' => 2,
                        'bind_token' => 'bind-token',
                        'bind_token_expires_at' => '1770000000',
                    ]);
                }

                if ($method === '/auth.v1.AuthService/VerifyToken') {
                    return new VerifyTokenResponse(['valid' => true, 'user_id' => '42', 'device_id' => '7']);
                }

                if ($method === '/auth.v1.AuthService/SendSocialPhoneCode') {
                    return new SendSocialPhoneCodeResponse(['expires_in' => 300, 'resend_after' => 60]);
                }

                return new BindSocialPhoneResponse();
            });

        $client = new AuthServiceClient(
            target: '127.0.0.1:1',
            timeoutMs: 300,
            socialLoginTimeoutMs: 7000,
            socialPhoneCodeTimeoutMs: 5000,
            socialBindTimeoutMs: 1000,
            socialHttpTimeoutMs: 3000,
            client: $grpc,
        );

        $result = $client->socialLogin('wechat', 'oauth-code', ['device_uuid' => 'device-1']);
        $verified = $client->verifyToken('access-token');
        $client->sendSocialPhoneCode('bind-token', '+8613800000000');

        $this->assertSame('phone_binding_required', $result['state']);
        $this->assertTrue($verified['valid']);
        $this->assertSame(7000, $timeouts['/auth.v1.AuthService/SocialLogin']);
        $this->assertSame(300, $timeouts['/auth.v1.AuthService/VerifyToken']);
        $this->assertSame(5000, $timeouts['/auth.v1.AuthService/SendSocialPhoneCode']);

        try {
            $client->bindSocialPhone('bind-token', '+8613800000000', '123456', ['device_uuid' => 'device-1']);
        } catch (\RuntimeException) {
            // The mock response intentionally has no login payload; the deadline was already captured.
        }

        $this->assertSame(1000, $timeouts['/auth.v1.AuthService/BindSocialPhone']);
    }

    public function testAThreeHundredMillisecondSocialDeadlineCannotSurviveTheSimulatedHalfSecondExchange(): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->once())
            ->method('call')
            ->willReturnCallback(function (string $method, mixed $request, string $responseClass, int $timeoutMs): object {
                if ($timeoutMs < 500) {
                    throw new GrpcCallException(
                        STATUS_DEADLINE_EXCEEDED,
                        null,
                        'simulated 500ms OAuth exchange exceeded 300ms deadline'
                    );
                }

                return new SocialLoginResponse(['state' => 2]);
            });

        $client = new AuthServiceClient(
            target: '127.0.0.1:1',
            socialLoginTimeoutMs: 300,
            socialHttpTimeoutMs: 100,
            client: $grpc,
        );

        try {
            $client->socialLogin('wechat', 'oauth-code', ['device_uuid' => 'device-1']);
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::RPC_TIMEOUT->value, $e->getCode());
            return;
        }

        $this->fail('Expected the simulated OAuth deadline to be mapped to RPC_TIMEOUT');
    }
}
