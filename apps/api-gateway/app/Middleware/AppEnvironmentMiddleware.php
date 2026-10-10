<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Middleware;

use Contracts\Errors\ErrorCode;
use RuntimeException;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * Restricts a route to the development environment.
 *
 * Phone (SMS) login is a development-only capability: production keeps this
 * middleware in the route pipeline, so the route can never be re-enabled by a
 * runtime flag or a request payload. The environment is resolved once while
 * Webman loads config/gateway.php and injected by the container; the middleware
 * never reads environment variables or config files per request. Anything that
 * is not development is treated as production and the route is rejected.
 */
final readonly class AppEnvironmentMiddleware implements MiddlewareInterface
{
    /**
     * @param string $environment 'development' or 'production'
     */
    public function __construct(private string $environment = 'production')
    {
    }

    public function process(Request $request, callable $handler): Response
    {
        if ($this->environment !== 'development') {
            throw new RuntimeException(
                ErrorCode::AUTH_UNAUTHORIZED->message(),
                ErrorCode::AUTH_UNAUTHORIZED->value
            );
        }

        return $handler($request);
    }
}
