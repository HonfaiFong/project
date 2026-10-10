<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Action\CreateWsTicketAction;
use Apps\ApiGateway\Controller\AuthController;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Http\GatewayRequest;
use Apps\ApiGateway\Response\PersonaPresenter;
use Contracts\Errors\ErrorCode;
use Exception;
use Tests\TestCase;

final class GatewaySecurityTest extends TestCase
{
    public function testPublicPersonaPresenterExposesOnlyMainProfileFields(): void
    {
        $persona = PersonaPresenter::one([
            'id'      => '30001',
            'user_id' => '20001',
            'type'    => 1,
            'status'  => 1,
            'nickname'=> 'Public name',
        ]);

        $this->assertArrayNotHasKey('user_id', $persona);
        $this->assertSame('30001', $persona['id']);
        $this->assertSame(1, $persona['status']);
        $this->assertSame('Public name', $persona['nickname']);
    }

    public function testPrivateMainKeepsProfileFieldsAndAliasIsOnlyModeDescriptor(): void
    {
        $main = PersonaPresenter::privateOne([
            'id' => '30001',
            'user_id' => '20001',
            'type' => 1,
            'status' => 1,
            'nickname' => 'Current name',
            'avatar_key' => 'avatar-main',
            'bio' => 'About me',
        ]);
        $this->assertSame('Current name', $main['nickname']);
        $this->assertSame('avatar-main', $main['avatar_key']);
        $this->assertSame('About me', $main['bio']);
        $this->assertSame('main', $main['identity_mode']);
        $this->assertTrue($main['profile_accessible']);

        $alias = PersonaPresenter::privateOne([
            'id' => '30002',
            'type' => 2,
            'status' => 1,
            'nickname' => 'Should not leak',
            'avatar_key' => 'system:anonymous',
        ]);
        $this->assertSame('alias', $alias['identity_mode']);
        $this->assertFalse($alias['profile_accessible']);
        $this->assertArrayNotHasKey('id', $alias);
        $this->assertArrayNotHasKey('nickname', $alias);
        $this->assertArrayNotHasKey('avatar_key', $alias);
    }

    /** @throws Exception 安全随机源不可用时抛出。 */
    public function testLoginRejectsMissingCredentialsBeforeCreatingUser(): void
    {
        $authClient = new AuthServiceClient('127.0.0.1:1');
        $userClient = new UserServiceClient('127.0.0.1:1');
        $action = new CreateWsTicketAction($authClient, $userClient);
        $controller = new AuthController($authClient, $action, $this->validationFactory());

        $request = new GatewayRequest("POST /api/v1/auth/login HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\n\r\n" . json_encode(['phone' => '+8613800000000']));
        $response = $controller->login($request);
        $body = json_decode($response->rawBody(), true);

        $this->assertSame(ErrorCode::AUTH_INVALID_CREDENTIALS->value, $body['code']);
    }

    public function testLogoutRejectsMissingAccessToken(): void
    {
        $authClient = new AuthServiceClient('127.0.0.1:1');
        $userClient = new UserServiceClient('127.0.0.1:1');
        $action = new CreateWsTicketAction($authClient, $userClient);
        $controller = new AuthController($authClient, $action, $this->validationFactory());

        $request = new GatewayRequest("POST /api/v1/auth/logout HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\n\r\n" . json_encode(['refresh_token' => 'refresh-token']));
        $response = $controller->logout($request);
        $body = json_decode($response->rawBody(), true);

        $this->assertSame(ErrorCode::AUTH_UNAUTHORIZED->value, $body['code']);
    }
}
