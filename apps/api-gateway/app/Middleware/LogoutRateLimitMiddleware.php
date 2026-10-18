<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Middleware;

use Apps\ApiGateway\Http\TrustedProxyClientIpResolver;
use Closure;

/** Separate Redis buckets keep logout recovery independent from social login traffic. */
final class LogoutRateLimitMiddleware extends SocialLoginRateLimitMiddleware
{
    /** @param Closure(string, int): int|null $counter */
    public function __construct(
        ?Closure $counter = null,
        int $limit = 60,
        int $windowSeconds = 60,
        int $deviceLimit = 20,
        ?TrustedProxyClientIpResolver $clientIpResolver = null,
        string $environment = 'production',
    ) {
        parent::__construct(counter: $counter, limit: $limit, windowSeconds: $windowSeconds,
            clientIpResolver: $clientIpResolver, environment: $environment, scenario: 'logout',
            scenarioLimit: $limit, deviceLimit: $deviceLimit);
    }
}
