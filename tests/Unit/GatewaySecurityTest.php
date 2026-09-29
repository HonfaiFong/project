<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Response\PersonaPresenter;
use Contracts\Errors\ErrorCode;
use Tests\TestCase;
use Apps\ApiGateway\Controller\AuthController;

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
        $controller = new AuthController(
            new AuthServiceClient('127.0.0.1:1'),
            new UserServiceClient('127.0.0.1:1')
        );

        $response = $controller->login(['phone' => '+8613800000000'], []);
        $body = json_decode($response['body'], true);

        $this->assertSame(ErrorCode::AUTH_INVALID_CREDENTIALS->value, $body['code']);
    }

    public function testLogoutRejectsMissingAccessToken(): void
    {
        $controller = new AuthController(
            new AuthServiceClient('127.0.0.1:1'),
            new UserServiceClient('127.0.0.1:1')
        );

        $response = $controller->logout(['refresh_token' => 'refresh-token'], []);
        $body = json_decode($response['body'], true);

        $this->assertSame(ErrorCode::AUTH_UNAUTHORIZED->value, $body['code']);
    }
}
