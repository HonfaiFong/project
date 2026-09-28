<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Apps\ApiGateway\Response\ApiResponse;

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

        if ($this->authClient) {
            try {
                $res = $this->authClient->checkHealth();
                $services['auth_service'] = ($res['status'] ?? 0) === 1 ? 'SERVING' : 'NOT_SERVING';
            } catch (\Throwable $e) {
                $services['auth_service'] = 'DOWN: ' . $e->getMessage();
            }
        }

        if ($this->userClient) {
            try {
                $res = $this->userClient->checkHealth();
                $services['user_service'] = ($res['status'] ?? 0) === 1 ? 'SERVING' : 'NOT_SERVING';
            } catch (\Throwable $e) {
                $services['user_service'] = 'DOWN: ' . $e->getMessage();
            }
        }

        return ApiResponse::success([
            'status'   => 'SERVING',
            'services' => $services,
        ]);
    }
}
