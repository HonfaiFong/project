<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Middleware;

use Apps\ApiGateway\Http\GatewayRequest;
use LogicException;
use Packages\Observability\Span;
use Packages\Observability\Tracer;
use Packages\Tracing\TraceContext;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class TraceMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        if (!$request instanceof GatewayRequest) {
            throw new LogicException('GatewayRequest is required');
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
        $route = self::metricRoute($request->path());
        $scope = Tracer::enter('http.server', Span::KIND_SERVER, [
            'http.request.method' => strtoupper($request->method()),
            'http.route' => $route,
            'url.path' => $request->path(),
        ]);
        $status = 500;
        try {
            $response = $handler($request);
            $status = $response->getStatusCode();
            $scope->span->setAttribute('http.response.status_code', $status);
            if ($status >= 500) {
                $scope->span->setStatus(2, 'HTTP ' . $status);
            }

            return $response;
        } catch (Throwable $exception) {
            $scope->fail($exception);
            throw $exception;
        } finally {
            $scope->span->setAttribute('http.response.status_code', $status);
            $scope->close();
            TraceContext::reset();
        }
    }

    private static function metricRoute(string $path): string
    {
        $route = preg_replace('/\/[0-9]+(?=\/|$)/', '/{id}', $path) ?: $path;

        return preg_replace('/\/[0-9a-f]{16,}(?=\/|$)/i', '/{id}', $route) ?: $route;
    }
}
