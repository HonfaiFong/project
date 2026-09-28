<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Middleware;

use Packages\Tracing\TraceContext;

final class TraceMiddleware
{
    /**
     * @param array<string, string> $headers
     */
    public static function process(array $headers): TraceContext
    {
        $normalized = [];
        foreach ($headers as $k => $v) {
            $normalized[strtolower((string)$k)] = (string)$v;
        }

        $requestId = $normalized['x-request-id'] ?? null;
        $traceId = $normalized['x-trace-id'] ?? null;
        $deviceId = $normalized['x-device-id'] ?? null;
        $appVersion = $normalized['x-app-version'] ?? null;
        $traceparent = $normalized['traceparent'] ?? null;

        $ctx = TraceContext::create(
            requestId: $requestId,
            traceId: $traceId,
            deviceId: $deviceId,
            appVersion: $appVersion,
            traceparent: $traceparent
        );

        TraceContext::setCurrent($ctx);
        return $ctx;
    }
}
