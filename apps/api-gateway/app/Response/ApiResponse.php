<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Response;

use Contracts\Errors\ErrorCode;
use Packages\Tracing\TraceContext;

final class ApiResponse
{
    /**
     * @param array<string, mixed>|object|null $data
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function success(mixed $data = [], int $httpStatus = 200): array
    {
        $trace = TraceContext::current();

        $body = json_encode([
            'code'       => 0,
            'message'    => 'ok',
            'request_id' => $trace->requestId,
            'data'       => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'status'  => $httpStatus,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Request-Id' => $trace->requestId,
                'X-Trace-Id'   => $trace->traceId,
            ],
            'body'    => $body,
        ];
    }

    /**
     * @param array<string, mixed> $details
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function error(
        int $code,
        string $message,
        array $details = [],
        ?int $httpStatus = null
    ): array {
        $trace = TraceContext::current();

        if ($httpStatus === null) {
            $enum = ErrorCode::tryFrom($code);
            $httpStatus = $enum ? $enum->httpStatus() : 500;
        }

        $body = json_encode([
            'code'       => $code,
            'message'    => $message,
            'request_id' => $trace->requestId,
            'details'    => $details,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'status'  => $httpStatus,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Request-Id' => $trace->requestId,
                'X-Trace-Id'   => $trace->traceId,
            ],
            'body'    => $body,
        ];
    }
}
