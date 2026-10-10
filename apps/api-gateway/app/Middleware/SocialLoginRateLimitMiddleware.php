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

class SocialLoginRateLimitMiddleware implements MiddlewareInterface
{
    protected TrustedProxyClientIpResolver $clientIpResolver;

    /**
     * @param Closure(string, int): int|null $counter 递增窗口计数器并返回最新计数值
     * @param list<string> $trustedProxyCidrs
     * @param string $environment 'development'（开发）或 'production'（生产）
     * @param string $scenario 'login' 或 'bind'，用于分隔各场景的限流桶
     * @param int $scenarioLimit 该场景单独的每 IP 限额
     * @param int $deviceLimit 每（IP, 设备）限额；仅作为收紧 IP 桶的风险信号，不能替代 IP 桶
     * @param int $identityLimit 每认证用户的限额（bind 场景）；为 0 时禁用身份桶
     */
    public function __construct(
        protected ?Closure $counter = null,
        protected int $limit = 30,
        protected int $windowSeconds = 60,
        array $trustedProxyCidrs = [],
        ?TrustedProxyClientIpResolver $clientIpResolver = null,
        protected string $environment = 'production',
        protected string $scenario = 'login',
        protected int $scenarioLimit = 30,
        protected int $deviceLimit = 20,
        protected int $identityLimit = 0,
        protected int $identityWindowSeconds = 3600,
    ) {
        if (!in_array($this->scenario, ['login', 'bind'], true)) {
            throw new InvalidArgumentException('Social rate limit scenario must be login or bind');
        }
        foreach ([
            'SOCIAL_LOGIN_RATE_LIMIT' => $this->limit,
            'SOCIAL_LOGIN_RATE_WINDOW_SECONDS' => $this->windowSeconds,
            'SOCIAL_LOGIN_RATE_SCENARIO_LIMIT' => $this->scenarioLimit,
            'SOCIAL_LOGIN_RATE_DEVICE_LIMIT' => $this->deviceLimit,
        ] as $setting => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException($setting . ' must resolve to a positive rate limit');
            }
        }
        if ($this->identityLimit !== 0 && ($this->identityLimit < 1 || $this->identityWindowSeconds < 1)) {
            throw new InvalidArgumentException('SOCIAL_BIND_USER_RATE_LIMIT / SOCIAL_BIND_USER_RATE_WINDOW_SECONDS must be positive when the identity bucket is enabled');
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
            foreach ($this->buckets($request) as $bucket) {
                $count = ($this->counter)($bucket['key'], $bucket['window_seconds']);
                if ($count > $bucket['limit']) {
                    throw new RuntimeException(
                        ErrorCode::RATE_LIMIT_EXCEEDED->message(),
                        ErrorCode::RATE_LIMIT_EXCEEDED->value
                    );
                }
            }
        } catch (Throwable $e) {
            $rateLimited = $e instanceof RuntimeException
                && $e->getCode() === ErrorCode::RATE_LIMIT_EXCEEDED->value;
            // 限流触发的拒绝在任何环境都生效；计数器故障在生产环境 fail-closed，仅开发环境放行。
            if ($rateLimited || $this->isProduction()) {
                if (!$rateLimited) {
                    $e = new RuntimeException(
                        'Social login rate limiter is unavailable',
                        ErrorCode::SERVICE_UNAVAILABLE->value,
                        $e
                    );
                }

                throw $e;
            }
        }

        return $handler($request);
    }

    /**
     * 分层限流桶，所有桶都必须通过：
     *
     * - IP 全局：同一客户端 IP 在 login 与 bind 之间共享一个总上限。
     *   给得较宽，因为运营商 CGNAT 会把多个真实用户压在同一个 IPv4 后面；
     *   细粒度限额由场景桶承担。
     * - IP + 场景：把微信/QQ 登录流量与绑定流量分开，互不挤占额度。
     * - IP + 设备：针对用同一设备身份高频请求的脚本客户端的风险信号。
     *   可选且严格叠加——轮换伪造的 device_uuid 只会铸造新的设备桶，
     *   永远不会抬高 IP 桶，因此伪造该头无法绕过限流。
     * - 用户 + 场景（仅 bind）：同一认证账号反复绑定会命中自己独立的
     *   长窗口桶，与其来源 IP 无关。
     *
     * @return list<array{key: string, limit: int, window_seconds: int}>
     */
    protected function buckets(Request $request): array
    {
        $ipBucketId = $this->ipBucketId($this->clientIpResolver->resolve($request));

        $buckets = [
            [
                'key'            => 'auth:gateway:social-login:' . hash('sha256', $ipBucketId),
                'limit'          => $this->limit,
                'window_seconds' => $this->windowSeconds,
            ],
            [
                'key'            => 'auth:gateway:social-login:' . $this->scenario . ':' . hash('sha256', $ipBucketId),
                'limit'          => $this->scenarioLimit,
                'window_seconds' => $this->windowSeconds,
            ],
        ];

        $deviceId = $this->deviceId($request);
        if (filled($deviceId)) {
            $buckets[] = [
                'key'            => 'auth:gateway:social-login:device:' . hash('sha256', $ipBucketId . '|' . $deviceId),
                'limit'          => $this->deviceLimit,
                'window_seconds' => $this->windowSeconds,
            ];
        }

        $userId = $this->authenticatedUserId($request);
        if ($this->identityLimit > 0 && filled($userId)) {
            $buckets[] = [
                'key'            => 'auth:gateway:social-login:user:' . $this->scenario . ':' . hash('sha256', $userId),
                'limit'          => $this->identityLimit,
                'window_seconds' => $this->identityWindowSeconds,
            ];
        }

        return $buckets;
    }

    private function isProduction(): bool
    {
        return $this->environment !== 'development';
    }

    /**
     * IPv4 客户端按完整地址作 key。IPv6 客户端按 /64 作 key：
     * 低 64 位接口标识由终端用户控制，不归并的话就能无限轮换、取之不竭地
     * 造出新桶，而 /64 正是单个入网用户应占用的粒度。
     */
    private function ipBucketId(string $clientIp): string
    {
        $packed = inet_pton($clientIp);
        if ($packed !== false && strlen($packed) === 16) {
            $clientIp = inet_ntop(substr($packed, 0, 8) . str_repeat("\x00", 8)) . '/64';
        }

        return $clientIp;
    }

    /**
     * 与 login/bind 载荷携带的设备身份一致（device_uuid 字段、x-device-id 头）。
     * 缺失即不加设备桶；IP 桶仍然生效。
     */
    private function deviceId(Request $request): string
    {
        $device = (array)$request->post('device', []);
        $deviceId = is_scalar($device['device_uuid'] ?? null) ? trim((string)$device['device_uuid']) : '';
        if (blank($deviceId)) {
            $header = $request->header('x-device-id');
            $deviceId = is_scalar($header) ? trim((string)$header) : '';
        }

        // 客户端可控的输入只用于计算哈希；限制长度，防止超大载荷把每个请求都变成昂贵的哈希运算。
        return substr($deviceId, 0, 128);
    }

    /**
     * AuthMiddleware 发布的已验证用户 ID。认证前（login）或限流器接线先于认证时为空。
     */
    protected function authenticatedUserId(Request $request): string
    {
        $context = property_exists($request, 'authContext') ? ($request->authContext ?? null) : null;

        return is_array($context) ? substr(trim((string)($context['user_id'] ?? '')), 0, 32) : '';
    }
}
