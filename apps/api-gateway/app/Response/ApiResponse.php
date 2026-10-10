<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Response;

use Contracts\Errors\ErrorCode;
use Packages\Tracing\TraceContext;
use Webman\Http\Response;

final class ApiResponse
{
    /**
     * @param array<string, mixed>|object|null $data
     */
    public static function success(mixed $data = [], int $httpStatus = 200, ?TraceContext $trace = null): Response
    {
        $trace ??= TraceContext::current();

        return json([
            'code'       => 0,
            'message'    => 'ok',
            'request_id' => $trace->requestId,
            'data'       => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->withStatus($httpStatus)
            ->withHeaders([
                'X-Request-Id' => $trace->requestId,
                'X-Trace-Id'   => $trace->traceId,
            ]);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(
        int $code,
        string $message,
        array $details = [],
        ?int $httpStatus = null,
        ?TraceContext $trace = null
    ): Response {
        $trace ??= TraceContext::current();

        $httpStatus ??= ErrorCode::tryFrom($code)?->httpStatus() ?? 500;

        return json([
            'code'       => $code,
            'message'    => $message,
            'request_id' => $trace->requestId,
            'details'    => $details,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->withStatus($httpStatus)
            ->withHeaders([
                'X-Request-Id' => $trace->requestId,
                'X-Trace-Id'   => $trace->traceId,
            ]);
    }
}
