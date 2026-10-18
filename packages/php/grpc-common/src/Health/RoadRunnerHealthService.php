<?php

declare(strict_types=1);

namespace Packages\GrpcCommon\Health;

use Contracts\Gen\Common\V1\HealthCheckRequest;
use Contracts\Gen\Common\V1\HealthCheckResponse;
use Contracts\Gen\Common\V1\HealthServiceInterface;
use Contracts\Gen\Common\V1\HealthStatus;
use Packages\GrpcCommon\InternalRpcAuthorizer;
use Packages\IdGenerator\WorkerId;
use Packages\Observability\Span;
use Packages\Observability\Tracer;
use Packages\Tracing\TraceContext;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Throwable;

/**
 * 由 RoadRunner 工作进程注册的 gRPC 健康服务（common.v1.HealthService）。
 *
 * 第一阶段：验证 RoadRunner PHP 工作进程存活且能响应。
 * 第二阶段：注入下游检查器（如 MySQL/Redis ping），支持就绪探测，区分存活检查（/live）和就绪检查（/ready）。
 */
final class RoadRunnerHealthService implements HealthServiceInterface
{
    /** @var (callable(): bool)|null */
    private $readinessChecker;

    /**
     * @param (callable(): bool)|null $readinessChecker
     * @param InternalRpcAuthorizer|null $authorizer
     */
    public function __construct(
        ?callable $readinessChecker = null,
        private readonly ?InternalRpcAuthorizer $authorizer = null,
    )
    {
        $this->readinessChecker = $readinessChecker;
    }

    public function Check(ContextInterface $ctx, HealthCheckRequest $in): HealthCheckResponse
    {
        $this->authorizer?->authorize($ctx, 'Check');
        TraceContext::setCurrent(TraceContext::create(
            requestId: $this->metadataValue($ctx, 'x-request-id'),
            traceId: $this->metadataValue($ctx, 'x-trace-id'),
            traceparent: $this->metadataValue($ctx, 'traceparent'),
        ));
        $scope = Tracer::enter('grpc.server', Span::KIND_SERVER, [
            'rpc.system' => 'grpc',
            'rpc.method' => 'Check',
            'server.address' => getenv('SERVICE_NAME') ?: 'grpc-service',
        ]);

        try {
            try {
                WorkerId::maintain(force: true);
            } catch (Throwable) {
                return (new HealthCheckResponse())
                    ->setStatus(HealthStatus::HEALTH_STATUS_NOT_SERVING)
                    ->setMessage('worker ID lease unavailable');
            }
            if ($this->readinessChecker !== null) {
                try {
                    $ok = ($this->readinessChecker)();
                    if (!$ok) {
                        return (new HealthCheckResponse())
                            ->setStatus(HealthStatus::HEALTH_STATUS_NOT_SERVING)
                            ->setMessage('downstream dependencies unhealthy');
                    }
                } catch (Throwable $e) {
                    return (new HealthCheckResponse())
                        ->setStatus(HealthStatus::HEALTH_STATUS_NOT_SERVING)
                        ->setMessage('readiness check failed: ' . $e->getMessage());
                }
            }

            return (new HealthCheckResponse())
                ->setStatus(HealthStatus::HEALTH_STATUS_SERVING)
                ->setMessage('ok');
        } catch (Throwable $exception) {
            $scope->fail($exception);
            throw $exception;
        } finally {
            $scope->close();
            TraceContext::reset();
        }
    }

    private function metadataValue(ContextInterface $ctx, string $key): ?string
    {
        $values = $ctx->getValues();
        $value = $values[$key] ?? null;
        if ($value === null && is_array($values['metadata'] ?? null)) {
            $value = $values['metadata'][$key] ?? null;
        }
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_scalar($value) && (string)$value !== '' ? (string)$value : null;
    }
}
