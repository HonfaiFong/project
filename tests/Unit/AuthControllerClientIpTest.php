<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Action\CreateWsTicketAction;
use Apps\ApiGateway\Controller\AuthController;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Http\GatewayRequest;
use Apps\ApiGateway\Http\TrustedProxyClientIpResolver;
use Contracts\Gen\Auth\V1\SendSocialPhoneCodeRequest;
use Contracts\Gen\Auth\V1\SendSocialPhoneCodeResponse;
use Packages\GrpcCommon\UnaryGrpcClient;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;

final class AuthControllerClientIpTest extends TestCase
{
    public function testSmsRateLimitContextUsesTheSharedTrustedProxyResolver(): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->once())
            ->method('call')
            ->with(
                '/auth.v1.AuthService/SendSocialPhoneCode',
                $this->isInstanceOf(SendSocialPhoneCodeRequest::class),
                SendSocialPhoneCodeResponse::class,
                5000,
                [
                    'x-client-ip' => '198.51.100.10',
                    'x-device-id' => 'device-1',
                ],
            )
            ->willReturn(new SendSocialPhoneCodeResponse(['expires_in' => 300, 'resend_after' => 60]));

        $authClient = new AuthServiceClient(
            target: '127.0.0.1:1',
            client: $grpc,
        );
        $controller = new AuthController(
            $authClient,
            new CreateWsTicketAction($authClient, new UserServiceClient('127.0.0.1:1')),
            new TrustedProxyClientIpResolver(['10.0.0.0/8']),
        );

        $response = $controller->sendSocialPhoneCode($this->request());

        $this->assertSame(200, $response->getStatusCode());
    }

    private function request(): GatewayRequest
    {
        $body = json_encode([
            'bind_token' => 'bind-token',
            'phone'      => '+8613800000000',
            'device'     => ['device_uuid' => 'device-1'],
        ], JSON_THROW_ON_ERROR);
        $request = new GatewayRequest(
            "POST /api/v1/auth/social/phone/code HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: application/json\r\n"
            . "X-Forwarded-For: 198.51.100.10, 10.2.0.1\r\n"
            . "\r\n"
            . $body
        );

        $connection = (new ReflectionClass(TcpConnection::class))->newInstanceWithoutConstructor();
        $remoteAddress = new ReflectionProperty(TcpConnection::class, 'remoteAddress');
        $remoteAddress->setAccessible(true);
        $remoteAddress->setValue($connection, '10.1.0.1:12345');
        $request->connection = $connection;

        return $request;
    }
}
