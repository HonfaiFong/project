<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

use Packages\Tracing\TraceContext;
use Random\RandomException;

final class GrpcClientContext
{
    /**
     * Build gRPC call options with trace headers and timeout deadline.
     *
     * @param int $timeoutMs Default deadline timeout in milliseconds (e.g. 150ms)
     * @param array<string, string|array<string>> $customMetadata Extra metadata
     * @param string|null $serviceToken Token configured for this target service
     * @return array{timeout: int, metadata: array<string, array<string>>, call_credentials?: mixed} Call options array compatible with gRPC PHP clients
     * @throws RandomException
     */
    public static function createCallOptions(
        int $timeoutMs = 150,
        array $customMetadata = [],
        ?string $serviceToken = null,
    ): array
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

        // Service identity is process configuration, never caller-provided
        // metadata. Keep these fields authoritative even when a client adds
        // custom metadata for an individual RPC.
        foreach (array_keys($metadata) as $key) {
            if (in_array(strtolower((string)$key), ['x-service-name', 'x-service-token'], true)) {
                unset($metadata[$key]);
            }
        }
        $serviceName = trim((string)(getenv('SERVICE_NAME') ?: ''));
        $serviceToken = trim($serviceToken ?? '');
        if ($serviceName !== '') {
            $metadata['x-service-name'] = [$serviceName];
        }
        if ($serviceToken !== '') {
            $metadata['x-service-token'] = [$serviceToken];
        }

        // Absolute deadline in epoch milliseconds. RoadRunner does not expose
        // the incoming call's remaining time to PHP workers, so receiving
        // services read this metadata (after caller authorization) to clamp
        // their own downstream hops to the caller's deadline.
        $metadata['x-deadline-unix-ms'] = [(string)(DeadlineContext::nowEpochMs() + $timeoutMs)];

        return [
            'timeout'  => $timeoutMs * 1000, // microseconds for grpc extension / client
            'metadata' => $metadata,
        ];
    }
}
