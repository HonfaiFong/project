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
    /** @var array<string, HealthCheckClient> */
    private readonly array $upstreamClients;

    /**
     * Accepts either a named map of clients:
     *   ['auth_service' => $authClient, 'user_service' => $userClient]
     * or positional clients for backward compatibility:
     *   ($authClient, $userClient)
     *
     * @param array<string, HealthCheckClient>|HealthCheckClient|null $clientsOrAuthClient
     */
    public function __construct(
        array|HealthCheckClient|null $clientsOrAuthClient = null,
        ?HealthCheckClient $userClient = null
    ) {
        if (is_array($clientsOrAuthClient)) {
            $this->upstreamClients = $clientsOrAuthClient;
        } else {
            $clients = [];
            if ($clientsOrAuthClient !== null) {
                $clients['auth_service'] = $clientsOrAuthClient;
            }
            if ($userClient !== null) {
                $clients['user_service'] = $userClient;
            }
            $this->upstreamClients = $clients;
        }
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
