<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Middleware;

use Apps\ApiGateway\Http\TrustedProxyClientIpResolver;
use Closure;

/**
 * 绑定场景的社交限流器：沿用同样的分层 IP 桶，但按 bind 场景计数并启用
 * 每认证用户桶，因此同一账号循环绑定身份时，无论换哪个 IP 都会被限住。
 * 必须注册在 AuthMiddleware 之后——用户桶需要 AuthMiddleware 发布在请求上的
 * 已验证用户 ID。
 */
final class SocialBindRateLimitMiddleware extends SocialLoginRateLimitMiddleware
{
    /**
     * 构造参数与父类一致，唯独去掉 scenario——这正是本类存在的意义：它永远不可能被接成 'login'。
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
