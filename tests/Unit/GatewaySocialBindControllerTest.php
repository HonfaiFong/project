<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Action\CreateWsTicketAction;
use Apps\ApiGateway\Controller\AuthController;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Http\GatewayRequest;
use Contracts\Errors\ErrorCode;
use Contracts\Gen\Auth\V1\BindSocialIdentityRequest;
use Contracts\Gen\Auth\V1\BindSocialIdentityResponse;
use JsonException;
use Packages\GrpcCommon\UnaryGrpcClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `POST /api/v1/auth/social/bind` 的网关协议约定。
 *
 * 绑定用户始终来自已验证的 Authorization 访问令牌；请求体中的 user_id 不得传入 AuthService 调用。
 */
final class GatewaySocialBindControllerTest extends TestCase
{
    /** @throws JsonException */
    public function testUnsupportedProviderIsRejectedBeforeAnyRpcCall(): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->never())->method('call');

        $response = $this->controller($grpc)->socialBindIdentity(
            $this->request(['provider' => 'weibo', 'code' => 'oauth-code'], 'Bearer access-token')
        );

        $this->assertSame(ErrorCode::AUTH_SOCIAL_PROVIDER_UNSUPPORTED->httpStatus(), $response->getStatusCode());
        $this->assertSame(
            ErrorCode::AUTH_SOCIAL_PROVIDER_UNSUPPORTED->value,
            json_decode($response->rawBody(), true)['code']
        );
    }

    /** @throws JsonException */
    public function testMissingCodeIsRejectedBeforeAnyRpcCall(): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->never())->method('call');

        $response = $this->controller($grpc)->socialBindIdentity(
            $this->request(['provider' => 'wechat', 'code' => '  '], 'Bearer access-token')
        );

        $this->assertSame(ErrorCode::AUTH_SOCIAL_CODE_INVALID->httpStatus(), $response->getStatusCode());
        $this->assertSame(
            ErrorCode::AUTH_SOCIAL_CODE_INVALID->value,
            json_decode($response->rawBody(), true)['code']
        );
    }

    /**
     * 路由中间件通常会阻止此请求，但控制器自身也须安全，缺少 Bearer 令牌时不能调用 AuthService。
     *
     * @throws JsonException
     */
    public function testMissingAuthorizationHeaderIsRejectedBeforeAnyRpcCall(): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->never())->method('call');

        $response = $this->controller($grpc)->socialBindIdentity(
            $this->request(['provider' => 'wechat', 'code' => 'oauth-code'], null)
        );

        $this->assertSame(ErrorCode::AUTH_UNAUTHORIZED->httpStatus(), $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws JsonException
     */
    #[DataProvider('bindingProvider')]
    public function testBindingUsesTheAccessTokenAndIgnoresBodyUserId(array $body, string $provider, string $code): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->once())
            ->method('call')
            ->with(
                '/auth.v1.AuthService/BindSocialIdentity',
                $this->callback(static function (mixed $request) use ($provider, $code): bool {
                    self::assertInstanceOf(BindSocialIdentityRequest::class, $request);
                    self::assertSame($code, $request->getCode());
                    self::assertSame('access-token', $request->getAccessToken());
                    self::assertSame(
                        $provider === 'wechat' ? 1 : 2,
                        $request->getProvider()
                    );

                    return true;
                }),
                BindSocialIdentityResponse::class,
                7000
            )
            ->willReturn(new BindSocialIdentityResponse(['user_id' => '20001', 'provider' => 1]));

        $response = $this->controller($grpc)->socialBindIdentity(
            $this->request($body, 'Bearer access-token')
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['user_id' => '20001', 'provider' => 'wechat'],
            json_decode($response->rawBody(), true)['data']
        );
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: string}> */
    public static function bindingProvider(): array
    {
        return [
            'wechat without body user_id' => [
                ['provider' => 'WECHAT', 'code' => 'code-1'],
                'wechat',
                'code-1',
            ],
            'qq and a spoofed body user_id' => [
                ['provider' => 'qq', 'code' => 'code-2', 'user_id' => '999999'],
                'qq',
                'code-2',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws JsonException
     */
    private function request(array $body, ?string $authorization): GatewayRequest
    {
        $authorizationHeader = $authorization === null ? '' : "Authorization: $authorization\r\n";

        return new GatewayRequest(
            "POST /api/v1/auth/social/bind HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: application/json\r\n"
            . $authorizationHeader
            . "\r\n"
            . json_encode($body, JSON_THROW_ON_ERROR)
        );
    }

    private function controller(UnaryGrpcClient $grpc): AuthController
    {
        $authClient = new AuthServiceClient(target: '127.0.0.1:1', client: $grpc);

        return new AuthController(
            $authClient,
            new CreateWsTicketAction($authClient, new UserServiceClient('127.0.0.1:1')),
            $this->validationFactory(),
        );
    }
}
