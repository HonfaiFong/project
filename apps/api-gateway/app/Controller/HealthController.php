<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Apps\ApiGateway\Grpc\HealthCheckClient;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;
use Contracts\Gen\Common\V1\HealthStatus;
use Throwable;

final class HealthController
{
    /**
     * @param array<string, HealthCheckClient> $upstreamClients
     */
    public function __construct(
        private readonly array $upstreamClients = []
    ) {
    }

    public function check(): array
    {
        $services = [
            'api_gateway' => 'SERVING',
        ];
        $healthy = true;

        foreach ($this->upstreamClients as $name => $client) {
            try {
                $res = $client->checkHealth();
                $status = $res['status'] === HealthStatus::HEALTH_STATUS_SERVING
                    ? 'SERVING'
                    : 'NOT_SERVING';
            } catch (Throwable $e) {
                $status = 'DOWN: ' . $e->getMessage();
            }

            if ($status !== 'SERVING') {
                $healthy = false;
            }
            $services[$name] = $status;
        }

        if (!$healthy) {
            // Degraded: report 503 so orchestrators and load balancers stop routing.
            return ApiResponse::error(
                ErrorCode::SERVICE_UNAVAILABLE->value,
                'one or more upstream services are unhealthy',
                ['services' => $services]
            );
        }

        return ApiResponse::success([
            'status'   => 'SERVING',
            'services' => $services,
        ]);
    }
}
