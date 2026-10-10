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
     * @param Closure(string, int): int|null $counter Increments a windowed counter and returns the new count
     * @param list<string> $trustedProxyCidrs
     * @param string $environment 'development' or 'production'
     * @param string $scenario 'login' or 'bind'; separates per-scenario buckets
     * @param int $scenarioLimit Per-IP allowance for this scenario alone
     * @param int $deviceLimit Per (IP, device) allowance; a risk signal that may
     *                         only tighten, never replace, the IP buckets
     * @param int $identityLimit Per-authenticated-user allowance (bind scenario);
     *                           zero disables the identity bucket
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
            // An exceeded bucket rejects the request in every environment; a
            // broken counter fails closed in production and bypasses only in
            // development.
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
     * Layered buckets, all of which must pass:
     *
     * - IP global: one ceiling per client IP across login and bind together.
     *   Sized generously because carrier CGNAT puts several real users behind
     *   one IPv4; scenario buckets carry the fine-grained limits.
     * - IP + scenario: separates WeChat/QQ login traffic from bind traffic so
     *   neither consumes the other's allowance.
     * - IP + device: a risk signal for scripted clients that hammer one device
     *   identity. Optional and strictly additive — rotating a forged
     *   device_uuid only mints new device buckets, it never lifts the IP
     *   buckets, so spoofing the header cannot bypass limiting.
     * - user + scenario (bind only): one authenticated account binding
     *   identities repeatedly gets its own long-window bucket, independent of
     *   which IP it comes from.
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
        if ($deviceId !== '') {
            $buckets[] = [
                'key'            => 'auth:gateway:social-login:device:' . hash('sha256', $ipBucketId . '|' . $deviceId),
                'limit'          => $this->deviceLimit,
                'window_seconds' => $this->windowSeconds,
            ];
        }

        $userId = $this->authenticatedUserId($request);
        if ($this->identityLimit > 0 && $userId !== '') {
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
     * IPv4 clients key on the full address. An IPv6 client keys on its /64:
     * the end user controls the low 64 interface bits and could otherwise
     * rotate them for an unbounded supply of fresh buckets, while a /64 is
     * the granularity a single subscriber is expected to occupy.
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
     * The same device identity the login/bind payloads carry (device_uuid
     * field, x-device-id header). Missing means no device bucket; the IP
     * buckets still apply.
     */
    private function deviceId(Request $request): string
    {
        $device = (array)$request->post('device', []);
        $deviceId = is_scalar($device['device_uuid'] ?? null) ? trim((string)$device['device_uuid']) : '';
        if ($deviceId === '') {
            $header = $request->header('x-device-id');
            $deviceId = is_scalar($header) ? trim((string)$header) : '';
        }

        // Client-controlled input only ever feeds a hash; cap it so a huge
        // payload cannot turn each request into expensive hashing work.
        return substr($deviceId, 0, 128);
    }

    /**
     * The verified user id published by AuthMiddleware. Empty before
     * authentication (login) or when wiring runs the limiter pre-auth.
     */
    protected function authenticatedUserId(Request $request): string
    {
        $context = property_exists($request, 'authContext') ? ($request->authContext ?? null) : null;

        return is_array($context) ? substr(trim((string)($context['user_id'] ?? '')), 0, 32) : '';
    }
}
