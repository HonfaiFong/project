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
            $key = $this->rateLimitKey($this->clientIpResolver->resolve($request));
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

    /**
     * IPv4 clients key on the full address. An IPv6 client keys on its /64:
     * the end user controls the low 64 interface bits and could otherwise
     * rotate them for an unbounded supply of fresh buckets, while a /64 is
     * the granularity a single subscriber is expected to occupy.
     */
    private function rateLimitKey(string $clientIp): string
    {
        $packed = inet_pton($clientIp);
        if ($packed !== false && strlen($packed) === 16) {
            $clientIp = inet_ntop(substr($packed, 0, 8) . str_repeat("\x00", 8)) . '/64';
        }

        return 'auth:gateway:social-login:' . hash('sha256', $clientIp);
    }
}
