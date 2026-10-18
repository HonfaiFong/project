<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Random\RandomException;
use Apps\ApiGateway\Grpc\HealthCheckClient;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;
use Contracts\Gen\Common\V1\HealthStatus;
use Packages\Observability\MetricsRegistry;
use Psr\Log\LoggerInterface;
use Throwable;
use Webman\Http\Response;

final readonly class HealthController
{
    /**
     * @param array<string, HealthCheckClient> $upstreamClients
     */
    public function __construct(
        private array $upstreamClients = [],
        private ?LoggerInterface $logger = null
    ) {
    }

    /** @throws RandomException */
    public function check(): Response
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
                if ($status !== 'SERVING') {
                    $this->logger?->warning('Health probe reported NOT_SERVING', [
                        'service' => $name,
                        'reason'  => $res['message'],
                    ]);
                }
            } catch (Throwable $e) {
                $status = 'NOT_SERVING';
                // 该端点无需认证，异常文本可能包含内部地址、连接错误或网络拓扑，
                // 因此只记录在服务端日志，公开响应仅保留状态。
                $this->logger?->error('Health probe failed', [
                    'service'   => $name,
                    'exception' => $e::class,
                    'reason'    => $e->getMessage(),
                ]);
            }

            if ($status !== 'SERVING') {
                $healthy = false;
            }
            MetricsRegistry::instance()->set('dependency_up', ['dependency' => $name], $status === 'SERVING' ? 1 : 0);
            $services[$name] = $status;
        }

        if (!$healthy) {
            // 降级：返回 503，让编排器与负载均衡器停止导流。
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
