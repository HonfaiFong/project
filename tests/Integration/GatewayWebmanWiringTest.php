<?php

declare(strict_types=1);

namespace Tests\Integration;

use Apps\ApiGateway\Http\GatewayRequest;
use Apps\ApiGateway\Process\Http;
use Contracts\Errors\ErrorCode;
use FastRoute\Dispatcher;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;
use Webman\Route;
use Webman\Config;
use Webman\Middleware;

final class GatewayWebmanWiringTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testPublicProtectedAndFallbackRoutesUseWebmanPipeline(): void
    {
        $gatewayPath = dirname(__DIR__, 2) . '/apps/api-gateway';
        Config::load($gatewayPath . '/config', ['route']);
        Middleware::load(config('middleware'));
        Route::load([$gatewayPath . '/config']);

        new Http(GatewayRequest::class, new Logger('test'), $gatewayPath . '/app', $gatewayPath . '/public');
        $this->assertTrue(Route::isDefaultRouteDisabled());

        [$status, $public] = Route::dispatch('POST', '/api/v1/auth/login');
        $this->assertSame(Dispatcher::FOUND, $status);
        $request = new GatewayRequest("POST /api/v1/auth/login HTTP/1.1\r\nHost: localhost\r\nX-Request-Id: public-123\r\n\r\n");
        $response = Http::getCallback('', 'ApiGateway', $public['callback'], [], true, $public['route'])($request);
        $this->assertSame(ErrorCode::INVALID_ARGUMENT->httpStatus(), $response->getStatusCode());
        $this->assertSame('public-123', json_decode($response->rawBody(), true)['request_id']);

        [$status, $protected] = Route::dispatch('GET', '/api/v1/me');
        $this->assertSame(Dispatcher::FOUND, $status);
        $request = new GatewayRequest("GET /api/v1/me HTTP/1.1\r\nHost: localhost\r\nX-Request-Id: protected-123\r\n\r\n");
        $response = Http::getCallback('', 'ApiGateway', $protected['callback'], [], true, $protected['route'])($request);
        $this->assertSame(ErrorCode::AUTH_UNAUTHORIZED->httpStatus(), $response->getStatusCode());
        $this->assertSame('protected-123', json_decode($response->rawBody(), true)['request_id']);

        [$status, , $params] = Route::dispatch('GET', '/api/v1/personas/123');
        $this->assertSame(Dispatcher::FOUND, $status);
        $this->assertSame('123', $params['personaId']);

        $request = new GatewayRequest("GET /missing HTTP/1.1\r\nHost: localhost\r\nX-Request-Id: missing-123\r\n\r\n");
        $response = Route::getFallback()($request);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('missing-123', json_decode($response->rawBody(), true)['request_id']);
    }
}
