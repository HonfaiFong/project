<?php

declare(strict_types=1);

namespace Packages\GrpcCommon\Health;

use Contracts\Gen\Common\V1\HealthCheckRequest;
use Contracts\Gen\Common\V1\HealthCheckResponse;
use Contracts\Gen\Common\V1\HealthServiceInterface;
use Contracts\Gen\Common\V1\HealthStatus;
use Spiral\RoadRunner\GRPC\ContextInterface;

/**
 * Minimal gRPC health service (common.v1.HealthService) registered by every
 * RoadRunner worker so upstream callers can probe liveness over gRPC itself
 * instead of trusting HTTP-only checks.
 */
final class RoadRunnerHealthService implements HealthServiceInterface
{
    public function Check(ContextInterface $ctx, HealthCheckRequest $in): HealthCheckResponse
    {
        return (new HealthCheckResponse())
            ->setStatus(HealthStatus::HEALTH_STATUS_SERVING)
            ->setMessage('ok');
    }
}
