<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Http\GatewayExceptionHandler;
use Apps\ApiGateway\Http\GatewayRequest;
use Apps\ApiGateway\Middleware\TraceMiddleware;
use Contracts\Errors\ErrorCode;
use PDOException;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\TestCase;

final class GatewayExceptionHandlerTest extends TestCase
{
    public function testKnownErrorCodeKeepsOriginalRequestIdAfterTraceCleanup(): void
    {
        $request = new GatewayRequest("GET /test HTTP/1.1\r\nHost: localhost\r\nX-Request-Id: request-123\r\n\r\n");
        $middleware = new TraceMiddleware();
        $middleware->process($request, static fn () => new \Webman\Http\Response());

        $handler = new GatewayExceptionHandler(new NullLogger(), false);
        $response = $handler->render($request, new RuntimeException('invalid params', ErrorCode::INVALID_ARGUMENT->value));

        $this->assertSame(ErrorCode::INVALID_ARGUMENT->httpStatus(), $response->getStatusCode());
        $body = json_decode($response->rawBody(), true);
        $this->assertSame(ErrorCode::INVALID_ARGUMENT->value, $body['code']);
        $this->assertSame('invalid params', $body['message']);
        $this->assertSame('request-123', $body['request_id']);
    }

    public function testUnknownPositiveExceptionCodeNormalizesToInternalServerError(): void
    {
        $request = new GatewayRequest("GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $handler = new GatewayExceptionHandler(new NullLogger(), false);
        $response = $handler->render($request, new PDOException('SQLSTATE[23000]: Integrity constraint violation', 23000));

        $this->assertSame(500, $response->getStatusCode());
        $body = json_decode($response->rawBody(), true);
        $this->assertSame(ErrorCode::INTERNAL_SERVER_ERROR->value, $body['code']);
        $this->assertSame(ErrorCode::INTERNAL_SERVER_ERROR->message(), $body['message']);
        $this->assertStringNotContainsString('SQLSTATE', $response->rawBody());
    }
}
