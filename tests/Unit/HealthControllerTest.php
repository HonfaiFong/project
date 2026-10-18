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
        $controller = new HealthController();

        $response = $controller->check();
        $body = json_decode($response->rawBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $body['code']);
        $this->assertSame('SERVING', $body['data']['status']);
        $this->assertSame('SERVING', $body['data']['services']['api_gateway']);
    }

    public function testDegradesTo503WhenUpstreamIsUnreachable(): void
    {
        $controller = new HealthController([
            'auth_service' => new AuthServiceClient('127.0.0.1:1'),
        ]);

        $response = $controller->check();
        $body = json_decode($response->rawBody(), true);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(ErrorCode::SERVICE_UNAVAILABLE->value, $body['code']);
        $this->assertArrayHasKey('auth_service', $body['details']['services']);
        $this->assertNotSame('SERVING', $body['details']['services']['auth_service']);
    }

    public function testReportsHealthyFromHealthCheckClientDouble(): void
    {
        $stubClient = new class implements \Apps\ApiGateway\Grpc\HealthCheckClient {
            public function checkHealth(): array
            {
                return ['status' => \Contracts\Gen\Common\V1\HealthStatus::HEALTH_STATUS_SERVING, 'message' => 'ok'];
            }
        };

        $controller = new HealthController(['custom_service' => $stubClient]);
        $response = $controller->check();
        $body = json_decode($response->rawBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('SERVING', $body['data']['services']['custom_service']);
    }

    public function testUnhealthyReasonIsReportedAndNextProbeCanRecover(): void
    {
        $client = $this->createMock(\Apps\ApiGateway\Grpc\HealthCheckClient::class);
        $client->method('checkHealth')->willReturnOnConsecutiveCalls(
            ['status' => \Contracts\Gen\Common\V1\HealthStatus::HEALTH_STATUS_NOT_SERVING, 'message' => 'worker ID lease unavailable'],
            ['status' => \Contracts\Gen\Common\V1\HealthStatus::HEALTH_STATUS_SERVING, 'message' => 'ok'],
        );
        $controller = new HealthController(['auth_service' => $client]);
        $failed = $controller->check();
        $body = json_decode($failed->rawBody(), true);
        $this->assertSame(503, $failed->getStatusCode());
        $this->assertSame('worker ID lease unavailable', $body['details']['failures']['auth_service']);
        $this->assertSame(200, $controller->check()->getStatusCode());
    }
}
