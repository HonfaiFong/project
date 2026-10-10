<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;
use Packages\Tracing\TraceContext;
use Tests\TestCase;

final class ApiResponseTest extends TestCase
{
    public function testSuccessPreservesEncodingStatusAndTraceHeaders(): void
    {
        $trace = TraceContext::create(requestId: 'response-request', traceId: str_repeat('a', 32));
        $response = ApiResponse::success(['path' => '/用户/avatar'], 201, $trace);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $this->assertSame($trace->requestId, $response->getHeader('X-Request-Id'));
        $this->assertSame($trace->traceId, $response->getHeader('X-Trace-Id'));
        $this->assertSame('{"code":0,"message":"ok","request_id":"response-request","data":{"path":"/用户/avatar"}}', $response->rawBody());
    }

    public function testErrorsRetainMappedExplicitAndUnknownStatuses(): void
    {
        $trace = TraceContext::create(requestId: 'error-request', traceId: str_repeat('b', 32));
        foreach ([
            [ErrorCode::AUTH_UNAUTHORIZED->value, null, 401],
            [ErrorCode::AUTH_UNAUTHORIZED->value, 409, 409],
            [-1, null, 500],
        ] as [$code, $override, $status]) {
            $response = ApiResponse::error($code, '错误', ['path' => '/a/b'], $override, $trace);

            $this->assertSame($status, $response->getStatusCode());
            $this->assertSame('application/json', $response->getHeader('Content-Type'));
            $this->assertSame($trace->requestId, $response->getHeader('X-Request-Id'));
            $this->assertSame($trace->traceId, $response->getHeader('X-Trace-Id'));
            $this->assertSame([
                'code' => $code,
                'message' => '错误',
                'request_id' => 'error-request',
                'details' => ['path' => '/a/b'],
            ], json_decode($response->rawBody(), true));
        }
    }
}
