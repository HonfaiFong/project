<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Http\WebmanGateway;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;
use Packages\Logging\JsonLogger;
use PDOException;
use RuntimeException;
use Tests\TestCase;
use Webman\Http\Request;

final class WebmanGatewayTest extends TestCase
{
    private WebmanGateway $gateway;

    protected function setUp(): void
    {
        $logger = new JsonLogger('test-gw', 'test', fopen('php://memory', 'rb+'));
        $this->gateway = new WebmanGateway($logger);
    }

    public function testKnownErrorCodePassesThrough(): void
    {
        $request = new Request("GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $response = $this->gateway->handle($request, function () {
            throw new RuntimeException('invalid params', ErrorCode::INVALID_ARGUMENT->value);
        });

        $this->assertSame(ErrorCode::INVALID_ARGUMENT->httpStatus(), $response->getStatusCode());
        $body = json_decode($response->rawBody(), true);
        $this->assertSame(ErrorCode::INVALID_ARGUMENT->value, $body['code']);
        $this->assertSame('invalid params', $body['message']);
    }

    public function testUnknownPositiveExceptionCodeNormalizesToInternalServerError(): void
    {
        $request = new Request("GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $response = $this->gateway->handle($request, function () {
            // Simulating an internal infrastructure failure (e.g. PDOException 23000)
            throw new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry', 23000);
        });

        $this->assertSame(500, $response->getStatusCode());
        $body = json_decode($response->rawBody(), true);
        $this->assertSame(ErrorCode::INTERNAL_SERVER_ERROR->value, $body['code']);
        $this->assertSame(ErrorCode::INTERNAL_SERVER_ERROR->message(), $body['message']);
        $this->assertStringNotContainsString('SQLSTATE', $response->rawBody());
    }
}
