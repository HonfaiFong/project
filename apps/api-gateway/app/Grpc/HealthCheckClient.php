<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Grpc;

interface HealthCheckClient
{
    /**
     * @return array{status: int, message: string}
     */
    public function checkHealth(): array;
}
