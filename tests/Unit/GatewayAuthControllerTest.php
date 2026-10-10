<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Action\CreateWsTicketAction;
use Apps\ApiGateway\Controller\AuthController;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Http\GatewayRequest;
use Contracts\Errors\ErrorCode;
use Contracts\Gen\Auth\V1\LoginRequest;
use Contracts\Gen\Auth\V1\LoginResponse;
use Packages\GrpcCommon\UnaryGrpcClient;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GatewayAuthControllerTest extends TestCase
{
    /** @throws Exception 安全随机源不可用时抛出。 */
    #[DataProvider('loginStatuses')]
    public function testLoginRetainsPayloadNormalizationAndResponseContract(bool $isNewUser, int $status): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->once())->method('call')->with(
            '/auth.v1.AuthService/Login',
            $this->callback(static function (LoginRequest $request): bool {
                self::assertSame('+8613800000000', $request->getPhone());
                self::assertSame('123456', $request->getCode());
                self::assertSame(' password ', $request->getPassword());
                self::assertSame('device-1', $request->getDevice()->getDeviceUuid());
                self::assertSame('ios', $request->getDevice()->getPlatform());

                return true;
            }),
            LoginResponse::class,
            300,
        )->willReturn(new LoginResponse([
            'user_id' => '42',
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'access_token_expires_at' => '100',
            'refresh_token_expires_at' => '200',
            'is_new_user' => $isNewUser,
        ]));
        $request = new GatewayRequest("POST /?phone=query HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $request->setHeader(['x-device-id' => 'device-1', 'x-platform' => 'ios']);
        $request->setPost(['phone' => ' +8613800000000 ', 'code' => ' 123456 ', 'password' => ' password ']);

        $response = $this->controller($grpc)->login($request);

        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame([
            'user_id' => '42',
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'access_token_expires_at' => 100,
            'refresh_token_expires_at' => 200,
            'is_new_user' => $isNewUser,
        ], json_decode($response->rawBody(), true)['data']);
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
    }

    public static function loginStatuses(): array
    {
        return [[false, 200], [true, 201]];
    }

    /** @throws Exception 安全随机源不可用时抛出。 */
    public function testQueryStringDoesNotSupplyLoginCredentials(): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->never())->method('call');
        $request = new GatewayRequest("POST /?phone=%2B8613800000000&code=123456 HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $response = $this->controller($grpc)->login($request);

        $this->assertSame(ErrorCode::INVALID_ARGUMENT->value, json_decode($response->rawBody(), true)['code']);
    }

    /** @throws Exception 安全随机源不可用时抛出。 */
    #[DataProvider('invalidSocialCredentials')]
    public function testBothSocialEndpointsRetainValidationErrorsAndPrecedence(array $body, ErrorCode $error): void
    {
        $grpc = $this->createMock(UnaryGrpcClient::class);
        $grpc->expects($this->never())->method('call');
        $controller = $this->controller($grpc);

        foreach (['socialLogin', 'socialBindIdentity'] as $method) {
            $request = new GatewayRequest("POST / HTTP/1.1\r\nHost: localhost\r\n\r\n");
            $request->setPost($body);
            $response = $controller->$method($request);

            $this->assertSame($error->httpStatus(), $response->getStatusCode());
            $this->assertSame($error->value, json_decode($response->rawBody(), true)['code']);
            $this->assertSame($error->message(), json_decode($response->rawBody(), true)['message']);
        }
    }

    public static function invalidSocialCredentials(): array
    {
        return [
            'missing both' => [[], ErrorCode::AUTH_SOCIAL_PROVIDER_UNSUPPORTED],
            'unsupported provider wins' => [['provider' => 'weibo', 'code' => ''], ErrorCode::AUTH_SOCIAL_PROVIDER_UNSUPPORTED],
            'blank code' => [['provider' => ' WECHAT ', 'code' => '  '], ErrorCode::AUTH_SOCIAL_CODE_INVALID],
            'null code' => [['provider' => 'qq', 'code' => null], ErrorCode::AUTH_SOCIAL_CODE_INVALID],
        ];
    }

    private function controller(UnaryGrpcClient $grpc): AuthController
    {
        $auth = new AuthServiceClient(client: $grpc);

        return new AuthController(
            $auth,
            new CreateWsTicketAction($auth, new UserServiceClient('127.0.0.1:1')),
            $this->validationFactory(),
        );
    }
}
