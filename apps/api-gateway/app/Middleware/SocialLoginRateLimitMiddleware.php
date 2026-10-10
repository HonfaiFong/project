<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Middleware;

use Apps\ApiGateway\Http\TrustedProxyClientIpResolver;
use Closure;
use Contracts\Errors\ErrorCode;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final readonly class SocialLoginRateLimitMiddleware implements MiddlewareInterface
{
    private TrustedProxyClientIpResolver $clientIpResolver;

    /**
     * @param Closure(string, int): int|null $counter
     * @param list<string> $trustedProxyCidrs
     * @param string $environment 'development' or 'production'
     */
    public function __construct(
        private ?Closure $counter = null,
        private int $limit = 30,
        private int $windowSeconds = 60,
        array $trustedProxyCidrs = [],
        ?TrustedProxyClientIpResolver $clientIpResolver = null,
        private string $environment = 'production',
    ) {
        if ($this->limit < 1 || $this->windowSeconds < 1) {
            throw new InvalidArgumentException('Social login rate limit settings must be positive');
        }

        $this->clientIpResolver = $clientIpResolver ?? new TrustedProxyClientIpResolver($trustedProxyCidrs);
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

        try {
            $key = 'auth:gateway:social-login:' . hash('sha256', $this->clientIpResolver->resolve($request));
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
        return $this->environment !== 'development';
    }
}
