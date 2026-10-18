<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Contracts\Gen\Common\V1\HealthCheckRequest;
use Contracts\Gen\Common\V1\HealthCheckResponse;
use Contracts\Gen\Common\V1\HealthServiceInterface;
use Contracts\Gen\Common\V1\HealthStatus;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\Server;
use Spiral\RoadRunner\GRPC\StatusCode;
use Spiral\RoadRunner\Worker;

$service = new class implements HealthServiceInterface {
    /** @var array<string, int> */
    private array $counts = [];

    public function Check(ContextInterface $ctx, HealthCheckRequest $in): HealthCheckResponse
    {
        $scenario = $in->getService();
        if (str_starts_with($scenario, 'count:')) {
            return new HealthCheckResponse(['message' => (string)($this->counts[substr($scenario, 6)] ?? 0)]);
        }
        $count = $this->counts[$scenario] = ($this->counts[$scenario] ?? 0) + 1;
        if ($scenario === 'always-unavailable') {
            throw new GRPCException('transport unavailable', StatusCode::UNAVAILABLE);
        }
        if ($scenario === 'unauthenticated') {
            throw new GRPCException('not authorized', StatusCode::UNAUTHENTICATED);
        }
        if ($scenario === 'slow') {
            usleep(500_000);
        }
        return new HealthCheckResponse([
            'status' => $scenario === 'not-serving' ? HealthStatus::HEALTH_STATUS_NOT_SERVING : HealthStatus::HEALTH_STATUS_SERVING,
            'message' => (string)$count,
        ]);
    }
};
$server = new Server();
$server->registerService(HealthServiceInterface::class, $service);
$server->serve(Worker::create());
