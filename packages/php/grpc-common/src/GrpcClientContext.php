<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

use Packages\Tracing\TraceContext;
use Random\RandomException;

final class GrpcClientContext
{
    /**
     * 构建包含追踪头和超时截止时间的 gRPC 调用选项。
     *
     * @param int $timeoutMs 默认截止时间超时，单位为毫秒（如 150ms）
     * @param array<string, string|array<string>> $customMetadata 额外元数据
     * @param string|null $serviceToken 为此目标服务配置的令牌
     * @return array{timeout: int, metadata: array<string, array<string>>, call_credentials?: mixed} 兼容 gRPC PHP 客户端的调用选项数组
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

        // 服务身份属于进程配置，不来自调用方元数据；即使客户端为单次 RPC 添加自定义元数据，这些字段也须保持权威性。
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

        // 以 Unix 纪元毫秒表示的绝对截止时间。RoadRunner 不向 PHP 工作进程暴露入站调用的剩余时间，因此接收服务在调用方授
        // 权后读取此元数据，限制下游各跳预算。
        $metadata['x-deadline-unix-ms'] = [(string)(DeadlineContext::nowEpochMs() + $timeoutMs)];

        return [
            'timeout'  => $timeoutMs * 1000, // gRPC 扩展和客户端使用微秒
            'metadata' => $metadata,
        ];
    }
}
