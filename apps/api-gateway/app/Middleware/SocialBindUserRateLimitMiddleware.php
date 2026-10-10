<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Middleware;

use Closure;
use LogicException;
use Webman\Http\Request;

/** 认证后仅强制执行已验证用户的绑定限额。 */
final class SocialBindUserRateLimitMiddleware extends SocialLoginRateLimitMiddleware
{
    /** @param Closure(string, int): int|null $counter */
    public function __construct(
        ?Closure $counter = null,
        int $identityLimit = 10,
        int $identityWindowSeconds = 3600,
        string $environment = 'production',
    ) {
        if ($identityLimit < 1 || $identityWindowSeconds < 1) {
            throw new \InvalidArgumentException('Social bind user limit and window must be positive');
        }
        parent::__construct(
            counter: $counter,
            environment: $environment,
            scenario: 'bind',
            identityLimit: $identityLimit,
            identityWindowSeconds: $identityWindowSeconds,
        );
    }

    protected function buckets(Request $request): array
    {
        $userId = $this->authenticatedUserId($request);
        if (empty($userId)) {
            throw new LogicException('Social bind user limiter requires an authenticated user');
        }

        return [[
            'key'            => 'auth:gateway:social-login:user:bind:' . hash('sha256', $userId),
            'limit'          => $this->identityLimit,
            'window_seconds' => $this->identityWindowSeconds,
        ]];
    }
}
