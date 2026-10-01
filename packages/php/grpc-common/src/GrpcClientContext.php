<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

use Packages\Tracing\TraceContext;

final class GrpcClientContext
{
    /**
     * Build gRPC call options with trace headers and timeout deadline.
     *
     * @param int $timeoutMs Default deadline timeout in milliseconds (e.g. 150ms)
     * @param array<string, string|array<string>> $customMetadata Extra metadata
     * @return array{timeout: int, metadata: array<string, array<string>>, call_credentials?: mixed} Call options array compatible with gRPC PHP clients
     */
    public static function createCallOptions(int $timeoutMs = 150, array $customMetadata = []): array
    {
        $trace = TraceContext::current();

        $metadata = [
            'x-request-id' => [$trace->requestId],
            'x-trace-id'   => [$trace->traceId],
        ];

        if ($trace->userId !== null) {
            $metadata['x-user-id'] = [$trace->userId];
        }
        if ($trace->personaId !== null) {
            $metadata['x-persona-id'] = [$trace->personaId];
        }
        if ($trace->deviceId !== null) {
            $metadata['x-device-id'] = [$trace->deviceId];
        }
        if ($trace->appVersion !== null) {
            $metadata['x-app-version'] = [$trace->appVersion];
        }
        if ($trace->traceparent !== null) {
            $metadata['traceparent'] = [$trace->traceparent];
        }

        foreach ($customMetadata as $k => $v) {
            $metadata[$k] = is_array($v) ? $v : [$v];
        }

        return [
            'timeout'  => $timeoutMs * 1000, // microseconds for grpc extension / client
            'metadata' => $metadata,
        ];
    }
}
