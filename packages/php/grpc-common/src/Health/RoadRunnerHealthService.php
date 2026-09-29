<?php

declare(strict_types=1);

namespace Packages\GrpcCommon\Health;

use Contracts\Gen\Common\V1\HealthCheckRequest;
use Contracts\Gen\Common\V1\HealthCheckResponse;
use Contracts\Gen\Common\V1\HealthServiceInterface;
use Contracts\Gen\Common\V1\HealthStatus;
use Spiral\RoadRunner\GRPC\ContextInterface;

/**
 * gRPC health service (common.v1.HealthService) registered by RoadRunner workers.
 *
 * Current Phase 1 scope: proves RoadRunner PHP worker liveness (process alive & responding).
 * Phase 2 evolution: support readiness probe by injecting downstream checkers (e.g. MySQL/Redis ping)
 * to distinguish Liveness (/live) from Readiness (/ready).
 */
final class RoadRunnerHealthService implements HealthServiceInterface
{
    /** @var (callable(): bool)|null */
    private $readinessChecker;

    /**
     * @param (callable(): bool)|null $readinessChecker
     */
    public function __construct(?callable $readinessChecker = null)
    {
        $this->readinessChecker = $readinessChecker;
    }

    public function Check(ContextInterface $ctx, HealthCheckRequest $in): HealthCheckResponse
    {
        if ($this->readinessChecker !== null) {
            try {
                $ok = ($this->readinessChecker)();
                if (!$ok) {
                    return (new HealthCheckResponse())
                        ->setStatus(HealthStatus::HEALTH_STATUS_NOT_SERVING)
                        ->setMessage('downstream dependencies unhealthy');
                }
            } catch (\Throwable $e) {
                return (new HealthCheckResponse())
                    ->setStatus(HealthStatus::HEALTH_STATUS_NOT_SERVING)
                    ->setMessage('readiness check failed: ' . $e->getMessage());
            }
        }

        return (new HealthCheckResponse())
            ->setStatus(HealthStatus::HEALTH_STATUS_SERVING)
            ->setMessage('ok');
    }
}
