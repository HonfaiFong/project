<?php

declare(strict_types=1);

namespace Packages\Observability;

use Packages\Tracing\TraceContext;
use Throwable;

final class SpanScope
{
    private bool $closed = false;
    private ?Throwable $failure = null;

    public function __construct(
        public readonly Span $span,
        private readonly ?TraceContext $previous,
    ) {
        if ($span->kind() === Span::KIND_SERVER && str_starts_with($span->name(), 'http.')) {
            MetricsRegistry::instance()->addGauge('http_server_requests_in_flight', [
                'route' => (string)($span->attributes()['http.route'] ?? 'unknown'),
            ], 1);
        }
        if (in_array($span->kind(), [Span::KIND_SERVER, Span::KIND_CLIENT], true)
            && isset($span->attributes()['rpc.system'])) {
            MetricsRegistry::instance()->addGauge('grpc_' . ($span->kind() === Span::KIND_SERVER ? 'server' : 'client') . '_requests_in_flight', [
                'method' => (string)($span->attributes()['rpc.method'] ?? $span->name()),
                'target' => (string)($span->attributes()['server.address'] ?? 'local'),
            ], 1);
        }
    }

    public function fail(Throwable $failure): void
    {
        $this->failure = $failure;
        $this->span->recordException($failure);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->span->end();
        $this->recordMetrics();
        TraceContext::setCurrent($this->previous);
    }

    private function recordMetrics(): void
    {
        $duration = max(0, $this->span->endTimeNs() - $this->span->startTimeNs()) / 1_000_000_000;
        $attributes = $this->span->attributes();
        $status = $this->failure === null && $this->span->statusCode() !== 2 ? 'ok' : 'error';
        $registry = MetricsRegistry::instance();

        if ($this->span->kind() === Span::KIND_SERVER && str_starts_with($this->span->name(), 'http.')) {
            $route = (string)($attributes['http.route'] ?? 'unknown');
            $httpStatus = (string)($attributes['http.response.status_code'] ?? ($status === 'ok' ? '200' : '500'));
            $labels = ['method' => (string)($attributes['http.request.method'] ?? 'UNKNOWN'), 'route' => $route, 'status' => $httpStatus];
            $registry->inc('http_server_requests_total', $labels);
            $registry->observe('http_server_request_duration_seconds', $duration, $labels);
            $registry->addGauge('http_server_requests_in_flight', ['route' => $route], -1);
            if ((int)$httpStatus >= 500) {
                $registry->inc('http_server_errors_total', ['route' => $route]);
            }
            return;
        }

        if (isset($attributes['rpc.system'])) {
            $kind = $this->span->kind() === Span::KIND_SERVER ? 'server' : 'client';
            $labels = [
                'method' => (string)($attributes['rpc.method'] ?? $this->span->name()),
                'target' => (string)($attributes['server.address'] ?? 'local'),
                'status' => $status,
            ];
            $registry->inc('grpc_' . $kind . '_requests_total', $labels);
            $registry->observe('grpc_' . $kind . '_request_duration_seconds', $duration, $labels);
            $registry->addGauge('grpc_' . $kind . '_requests_in_flight', [
                'method' => $labels['method'],
                'target' => $labels['target'],
            ], -1);
            if ($status === 'error') {
                $registry->inc('grpc_' . $kind . '_errors_total', [
                    'method' => $labels['method'],
                    'target' => $labels['target'],
                ]);
            }
        }
    }
}
