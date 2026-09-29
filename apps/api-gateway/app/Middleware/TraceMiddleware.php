<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Middleware;

use Apps\ApiGateway\Http\GatewayRequest;
use Packages\Tracing\TraceContext;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class TraceMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        if (!$request instanceof GatewayRequest) {
            throw new \LogicException('GatewayRequest is required');
        }
        $ctx = TraceContext::create(
            requestId: $request->header('x-request-id'),
            traceId: $request->header('x-trace-id'),
            deviceId: $request->header('x-device-id'),
            appVersion: $request->header('x-app-version'),
            traceparent: $request->header('traceparent')
        );

        $request->traceContext = $ctx;
        TraceContext::setCurrent($ctx);
        try {
            return $handler($request);
        } finally {
            TraceContext::reset();
        }
    }
}
