<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;
use Contracts\Gen\Common\V1\HealthStatus;

final class HealthController
{
    public function __construct(
        private readonly ?AuthServiceClient $authClient = null,
        private readonly ?UserServiceClient $userClient = null
    ) {
    }

    public function check(): array
    {
        $services = [
            'api_gateway' => 'SERVING',
        ];
        $healthy = true;

        $clients = [
            'auth_service' => $this->authClient,
            'user_service' => $this->userClient,
        ];

        foreach ($clients as $name => $client) {
            if ($client === null) {
                continue;
            }

            try {
                $res = $client->checkHealth();
                $status = ($res['status'] ?? 0) === HealthStatus::HEALTH_STATUS_SERVING
                    ? 'SERVING'
                    : 'NOT_SERVING';
            } catch (\Throwable $e) {
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
