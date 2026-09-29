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
use Tests\TestCase;

final class GatewaySecurityTest extends TestCase
{
    public function testPublicPersonaPresenterRemovesOnlyUserId(): void
    {
        $persona = PersonaPresenter::one([
            'id'      => '30001',
            'user_id' => '20001',
            'status'  => 1,
            'nickname'=> 'Public name',
        ]);

        $this->assertFalse(array_key_exists('user_id', $persona));
        $this->assertSame('30001', $persona['id']);
        $this->assertSame(1, $persona['status']);
        $this->assertSame('Public name', $persona['nickname']);
    }

    public function testLoginRejectsMissingCredentialsBeforeCreatingUser(): void
    {
        $authClient = new AuthServiceClient('127.0.0.1:1');
        $userClient = new UserServiceClient('127.0.0.1:1');
        $action = new CreateWsTicketAction($authClient, $userClient);
        $controller = new AuthController($authClient, $action);

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
        $controller = new AuthController($authClient, $action);

        $request = new GatewayRequest("POST /api/v1/auth/logout HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\n\r\n" . json_encode(['refresh_token' => 'refresh-token']));
        $response = $controller->logout($request);
        $body = json_decode($response->rawBody(), true);

        $this->assertSame(ErrorCode::AUTH_UNAUTHORIZED->value, $body['code']);
    }
}
