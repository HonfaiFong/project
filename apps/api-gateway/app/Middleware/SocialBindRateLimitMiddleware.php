<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Middleware;

use Apps\ApiGateway\Http\TrustedProxyClientIpResolver;
use Closure;

/**
 * Bind-flavoured social rate limiter: same layered IP buckets, but counted in
 * the bind scenario and with the per-authenticated-user bucket enabled, so one
 * account binding identities in a loop is capped regardless of which IP it
 * comes from. Register it after AuthMiddleware — the user bucket needs the
 * verified user id AuthMiddleware publishes on the request.
 */
final class SocialBindRateLimitMiddleware extends SocialLoginRateLimitMiddleware
{
    /**
     * Same construction surface as the parent minus the scenario, which is
     * the entire point of this class: it can never be wired as 'login'.
     *
     * @param Closure(string, int): int|null $counter
     * @param list<string> $trustedProxyCidrs
     */
    public function __construct(
        ?Closure $counter = null,
        int $limit = 60,
        int $windowSeconds = 60,
        array $trustedProxyCidrs = [],
        ?TrustedProxyClientIpResolver $clientIpResolver = null,
        string $environment = 'production',
        int $scenarioLimit = 30,
        int $deviceLimit = 20,
        int $identityLimit = 10,
        int $identityWindowSeconds = 3600,
    ) {
        parent::__construct(
            counter: $counter,
            limit: $limit,
            windowSeconds: $windowSeconds,
            trustedProxyCidrs: $trustedProxyCidrs,
            clientIpResolver: $clientIpResolver,
            environment: $environment,
            scenario: 'bind',
            scenarioLimit: $scenarioLimit,
            deviceLimit: $deviceLimit,
            identityLimit: $identityLimit,
            identityWindowSeconds: $identityWindowSeconds,
        );
    }
}
