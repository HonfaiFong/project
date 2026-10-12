<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Middleware\MetricsAccessMiddleware;
use Tests\TestCase;
use Webman\Http\Request;
use Webman\Http\Response;

final class MetricsAccessMiddlewareTest extends TestCase
{
    public function testMissingAllowlistDeniesEvenLoopback(): void
    {
        $response = (new MetricsAccessMiddleware())->process(
            $this->request('127.0.0.1'),
            static fn (): Response => new Response(200),
        );
        self::assertSame(403, $response->getStatusCode());
    }

    public function testOnlySocketPeerCanGrantMetricsAccess(): void
    {
        $middleware = new MetricsAccessMiddleware(['10.20.0.0/16', 'fd42:1234::/64']);
        $handler = static fn (): Response => new Response(200);
        self::assertSame(200, $middleware->process($this->request('10.20.0.4'), $handler)->getStatusCode());
        self::assertSame(200, $middleware->process($this->request('fd42:1234::1'), $handler)->getStatusCode());
        self::assertSame(403, $middleware->process(
            $this->request('203.0.113.2', '10.20.0.4'), $handler,
        )->getStatusCode());
        self::assertSame(403, $middleware->process($this->request('unknown'), $handler)->getStatusCode());
    }

    private function request(string $peer, string $forwarded = ''): Request
    {
        return new class ($peer, $forwarded) extends Request {
            public function __construct(private readonly string $peer, string $forwarded)
            {
                parent::__construct("GET /metrics HTTP/1.1\r\nHost: localhost\r\nX-Forwarded-For: {$forwarded}\r\n\r\n");
            }

            public function getRemoteIp(): string
            {
                return $this->peer;
            }
        };
    }
}
