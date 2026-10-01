<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Middleware;

use Closure;
use Contracts\Errors\ErrorCode;
use RuntimeException;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class SocialLoginRateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param Closure(string, int): int|null $counter
     */
    public function __construct(
        private readonly ?Closure $counter = null,
        private readonly int $limit = 30,
        private readonly int $windowSeconds = 60,
    ) {
    }

    public function process(Request $request, callable $handler): Response
    {
        if ($this->counter === null) {
            if ($this->isProduction()) {
                throw new RuntimeException(
                    'Social login rate limiter is unavailable',
                    ErrorCode::SERVICE_UNAVAILABLE->value
                );
            }

            return $handler($request);
        }

        // Use the socket peer address for abuse control. Forwarded headers are
        // only safe after an explicitly configured trusted-proxy boundary.
        $ip = trim((string)$request->getRemoteIp());
        $key = 'auth:gateway:social-login:' . hash('sha256', $ip !== '' ? $ip : 'unknown');

        try {
            $count = ($this->counter)($key, $this->windowSeconds);
        } catch (Throwable $e) {
            if ($this->isProduction()) {
                throw new RuntimeException(
                    'Social login rate limiter is unavailable',
                    ErrorCode::SERVICE_UNAVAILABLE->value,
                    $e
                );
            }

            return $handler($request);
        }

        if ($count > $this->limit) {
            throw new RuntimeException(
                ErrorCode::RATE_LIMIT_EXCEEDED->message(),
                ErrorCode::RATE_LIMIT_EXCEEDED->value
            );
        }

        return $handler($request);
    }

    private function isProduction(): bool
    {
        $environment = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));

        return !in_array($environment, ['local', 'development', 'dev', 'testing'], true);
    }
}
