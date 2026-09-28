<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Controller\HealthController;
use Apps\ApiGateway\Grpc\AuthServiceClient;
use Contracts\Errors\ErrorCode;
use Tests\TestCase;

final class HealthControllerTest extends TestCase
{
    public function testServesWhenNoUpstreamClientsAreWired(): void
    {
        $controller = new HealthController(null, null);

        $response = $controller->check();
        $body = json_decode($response['body'], true);

        $this->assertSame(200, $response['status']);
        $this->assertSame(0, $body['code']);
        $this->assertSame('SERVING', $body['data']['status']);
        $this->assertSame('SERVING', $body['data']['services']['api_gateway']);
    }

    public function testDegradesTo503WhenUpstreamIsUnreachable(): void
    {
        $controller = new HealthController(
            new AuthServiceClient('127.0.0.1:1'),
            null
        );

        $response = $controller->check();
        $body = json_decode($response['body'], true);

        $this->assertSame(503, $response['status']);
        $this->assertSame(ErrorCode::SERVICE_UNAVAILABLE->value, $body['code']);
        $this->assertArrayHasKey('auth_service', $body['details']['services']);
        $this->assertNotSame('SERVING', $body['details']['services']['auth_service']);
    }
}
