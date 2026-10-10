<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Middleware\SocialBindRateLimitMiddleware;
use Apps\ApiGateway\Middleware\SocialLoginRateLimitMiddleware;
use Contracts\Errors\ErrorCode;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use Webman\Http\Request;
use Webman\Http\Response;

final class SocialLoginRateLimitMiddlewareTest extends TestCase
{
    public function testSocialLoginRequestsAreLimitedPerClientIp(): void
    {
        $keys = [];
        $middleware = new SocialLoginRateLimitMiddleware(
            counter: $this->recordingCounter($keys),
            limit: 2,
            windowSeconds: 60,
            environment: 'development',
        );
        $request = $this->request('192.0.2.10');

        $handler = static fn (Request $request): Response => new Response(200);
        $middleware->process($request, $handler);
        $middleware->process($request, $handler);

        try {
            $middleware->process($request, $handler);
        } catch (RuntimeException $e) {
            $this->assertSame(ErrorCode::RATE_LIMIT_EXCEEDED->value, $e->getCode());
            return;
        }

        $this->fail('Expected social login rate limit to reject the third request');
    }

    public function testLoginAndBindScenariosBucketSeparatelyButShareTheGlobalIpCeiling(): void
    {
        $keys = [];
        $counter = $this->recordingCounter($keys);
        $login = new SocialLoginRateLimitMiddleware(
            counter: $counter,
            environment: 'development',
        );
        $bind = new SocialBindRateLimitMiddleware(
            counter: $counter,
            environment: 'development',
        );
        $handler = static fn (Request $request): Response => new Response(200);

        $login->process($this->request('198.51.100.10'), $handler);
        $bind->process($this->request('198.51.100.10'), $handler);

        $loginScenario = 'auth:gateway:social-login:login:' . hash('sha256', '198.51.100.10');
        $bindScenario = 'auth:gateway:social-login:bind:' . hash('sha256', '198.51.100.10');
        $global = 'auth:gateway:social-login:' . hash('sha256', '198.51.100.10');

        $this->assertArrayHasKey($loginScenario, $keys);
        $this->assertArrayHasKey($bindScenario, $keys);
        // The IP ceiling is one shared bucket across both scenarios: several
        // CGNAT users behind one IPv4 hit one generous ceiling, while the
        // scenario buckets keep login and bind allowances independent.
        $this->assertSame(2, $keys[$global] ?? 0);
    }

    public function testSharedIpScenarioCeilingStillRejectsWhenOnlyTheGlobalBucketExceeds(): void
    {
        $hits = [];
        $middleware = new SocialLoginRateLimitMiddleware(
            counter: static function (string $key) use (&$hits): int {
                return $hits[$key] = ($hits[$key] ?? 0) + 1;
            },
            limit: 2,
            scenarioLimit: 100,
            windowSeconds: 60,
            environment: 'development',
        );
        $handler = static fn (Request $request): Response => new Response(200);

        for ($i = 0; $i < 2; $i++) {
            $middleware->process($this->request('198.51.100.10'), $handler);
        }

        try {
            $middleware->process($this->request('198.51.100.10'), $handler);
        } catch (RuntimeException $e) {
            $this->assertSame(ErrorCode::RATE_LIMIT_EXCEEDED->value, $e->getCode());
            return;
        }

        $this->fail('Expected the global IP ceiling to reject the third request');
    }

    public function testDeviceSignalAddsABucketButCannotBypassTheIpBuckets(): void
    {
        $keys = [];
        $middleware = new SocialLoginRateLimitMiddleware(
            counter: $this->recordingCounter($keys),
            deviceLimit: 2,
            environment: 'development',
        );
        $handler = static fn (Request $request): Response => new Response(200);

        $middleware->process(
            $this->request('198.51.100.10', ['x-device-id' => 'device-a']),
            $handler,
        );
        $middleware->process(
            $this->request('198.51.100.10', ['x-device-id' => 'device-b']),
            $handler,
        );

        $this->assertSame(1, $keys[$this->deviceKey('198.51.100.10', 'device-a')]);
        $this->assertSame(1, $keys[$this->deviceKey('198.51.100.10', 'device-b')]);
        // Rotating a forged device id mints fresh device buckets but never
        // lifts the IP buckets: both requests still counted on the IP keys.
        $this->assertSame(2, $keys['auth:gateway:social-login:' . hash('sha256', '198.51.100.10')]);
        $this->assertSame(2, $keys['auth:gateway:social-login:login:' . hash('sha256', '198.51.100.10')]);
    }

    public function testDeviceUuidFromThePayloadFeedsTheSameBucketAsTheHeader(): void
    {
        $keys = [];
        $middleware = new SocialLoginRateLimitMiddleware(
            counter: $this->recordingCounter($keys),
            environment: 'development',
        );
        $handler = static fn (Request $request): Response => new Response(200);

        $middleware->process(
            $this->request('198.51.100.10', [], ['device' => ['device_uuid' => 'payload-device']]),
            $handler,
        );
        $middleware->process(
            $this->request('198.51.100.10', ['x-device-id' => 'payload-device']),
            $handler,
        );

        $this->assertSame(2, $keys[$this->deviceKey('198.51.100.10', 'payload-device')]);
    }

    public function testBindCountsAnIdentityBucketForTheAuthenticatedUser(): void
    {
        $keys = [];
        $middleware = new SocialBindRateLimitMiddleware(
            counter: $this->recordingCounter($keys),
            identityLimit: 3,
            identityWindowSeconds: 3600,
            environment: 'development',
        );
        $handler = static fn (Request $request): Response => new Response(200);

        $middleware->process($this->request('198.51.100.10', authContext: ['user_id' => '737373']), $handler);
        $middleware->process($this->request('203.0.113.99', authContext: ['user_id' => '737373']), $handler);

        $identityKey = 'auth:gateway:social-login:user:bind:' . hash('sha256', '737373');
        // Same user from two different IPs still lands in one identity bucket.
        $this->assertSame(2, $keys[$identityKey] ?? 0);

        try {
            $middleware->process($this->request('203.0.113.99', authContext: ['user_id' => '737373']), $handler);
            $middleware->process($this->request('198.51.100.10', authContext: ['user_id' => '737373']), $handler);
        } catch (RuntimeException $e) {
            $this->assertSame(ErrorCode::RATE_LIMIT_EXCEEDED->value, $e->getCode());
            return;
        }

        $this->fail('Expected the per-user bind bucket to reject the fourth bind');
    }

    public function testBindWithoutAnAuthenticatedUserSkipsTheIdentityBucketInsteadOfKeyingOnEmpty(): void
    {
        $keys = [];
        $middleware = new SocialBindRateLimitMiddleware(
            counter: $this->recordingCounter($keys),
            identityLimit: 3,
            environment: 'development',
        );
        $handler = static fn (Request $request): Response => new Response(200);

        $middleware->process($this->request('198.51.100.10'), $handler);

        $this->assertArrayNotHasKey('auth:gateway:social-login:user:bind:' . hash('sha256', ''), $keys);
        $this->assertNotEmpty($keys);
    }

    public function testTrustedProxyForwardedClientsGetSeparateBuckets(): void
    {
        $keys = [];
        $middleware = new SocialLoginRateLimitMiddleware(
            counter: $this->recordingCounter($keys),
            trustedProxyCidrs: ['10.0.0.0/8'],
            environment: 'development',
        );
        $handler = static fn (Request $request): Response => new Response(200);

        $middleware->process(
            $this->request('10.1.0.1', ['x-forwarded-for' => '198.51.100.10, 10.2.0.1']),
            $handler,
        );
        $middleware->process(
            $this->request('10.1.0.1', ['x-forwarded-for' => '198.51.100.11, 10.2.0.1']),
            $handler,
        );

        $this->assertSame(1, $keys['auth:gateway:social-login:' . hash('sha256', '198.51.100.10')]);
        $this->assertSame(1, $keys['auth:gateway:social-login:' . hash('sha256', '198.51.100.11')]);
    }

    public function testDualCdnLbChainKeepsDistinctClientBuckets(): void
    {
        // Two clients arrive through the same dual CDN -> LB chain; they must
        // never share one bucket keyed on the shared proxy infrastructure.
        $keys = [];
        $middleware = new SocialLoginRateLimitMiddleware(
            counter: $this->recordingCounter($keys),
            trustedProxyCidrs: ['100.64.0.0/10', '10.0.0.0/8'],
            environment: 'development',
        );
        $handler = static fn (Request $request): Response => new Response(200);

        $middleware->process(
            $this->request('10.2.0.7', ['x-forwarded-for' => '198.51.100.10, 100.64.1.9']),
            $handler,
        );
        $middleware->process(
            $this->request('10.2.0.7', ['x-forwarded-for' => '198.51.100.11, 100.64.1.9']),
            $handler,
        );

        $this->assertSame(1, $keys['auth:gateway:social-login:' . hash('sha256', '198.51.100.10')]);
        $this->assertSame(1, $keys['auth:gateway:social-login:' . hash('sha256', '198.51.100.11')]);
    }

    public function testIpv4MappedDualStackChainKeysOnTheRealClient(): void
    {
        $keys = [];
        $middleware = new SocialLoginRateLimitMiddleware(
            counter: $this->recordingCounter($keys),
            trustedProxyCidrs: ['10.0.0.0/8'],
            environment: 'development',
        );
        $handler = static fn (Request $request): Response => new Response(200);

        $middleware->process(
            $this->request('::ffff:10.1.0.1', ['x-forwarded-for' => '::ffff:198.51.100.10']),
            $handler,
        );

        $this->assertSame(1, $keys['auth:gateway:social-login:' . hash('sha256', '198.51.100.10')]);
    }

    public function testIpv6ClientsShareOneBucketPerSlash64(): void
    {
        $keys = [];
        $middleware = new SocialLoginRateLimitMiddleware(
            counter: $this->recordingCounter($keys),
            trustedProxyCidrs: ['fd00::/8'],
            environment: 'development',
        );
        $handler = static fn (Request $request): Response => new Response(200);

        // Rotating the interface bits must not mint new buckets...
        $middleware->process(
            $this->request('fd00::1', ['x-forwarded-for' => '2001:db8:aaaa:1::1, fd00::2']),
            $handler,
        );
        $middleware->process(
            $this->request('fd00::1', ['x-forwarded-for' => '2001:db8:aaaa:1::dead, fd00::2']),
            $handler,
        );
        // ...while a different /64 is a different subscriber.
        $middleware->process(
            $this->request('fd00::1', ['x-forwarded-for' => '2001:db8:bbbb:1::1, fd00::2']),
            $handler,
        );

        $slash64 = 'auth:gateway:social-login:' . hash('sha256', '2001:db8:aaaa:1::/64');
        $this->assertSame(2, $keys[$slash64]);
        $this->assertSame(1, $keys['auth:gateway:social-login:' . hash('sha256', '2001:db8:bbbb:1::/64')]);
    }

    public function testUntrustedPeerCannotChooseBucketWithForwardedHeader(): void
    {
        $keys = [];
        $middleware = new SocialLoginRateLimitMiddleware(
            counter: $this->recordingCounter($keys),
            trustedProxyCidrs: ['10.0.0.0/8'],
            environment: 'development',
        );
        $handler = static fn (Request $request): Response => new Response(200);

        $middleware->process(
            $this->request('198.51.100.50', ['x-forwarded-for' => '203.0.113.10']),
            $handler,
        );

        $this->assertSame(1, $keys['auth:gateway:social-login:' . hash('sha256', '198.51.100.50')]);
    }

    public function testMalformedForwardedHopFallsBackToSocketPeer(): void
    {
        $keys = [];
        $middleware = new SocialLoginRateLimitMiddleware(
            counter: $this->recordingCounter($keys),
            trustedProxyCidrs: ['10.0.0.0/8'],
            environment: 'development',
        );
        $handler = static fn (Request $request): Response => new Response(200);

        $middleware->process(
            $this->request('10.1.0.1', ['x-forwarded-for' => '198.51.100.10, 10.2.0.1, forged']),
            $handler,
        );

        $this->assertSame(1, $keys['auth:gateway:social-login:' . hash('sha256', '10.1.0.1')]);
    }

    public function testInvalidTrustedProxyCidrFailsClosedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SocialLoginRateLimitMiddleware(trustedProxyCidrs: ['not-a-cidr']);
    }

    public function testInvalidScenarioIsRejectedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SocialLoginRateLimitMiddleware(scenario: 'password-reset');
    }

    public function testPublicAnyAddressTrustedProxyRangeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SocialLoginRateLimitMiddleware(trustedProxyCidrs: ['0.0.0.0/0']);
    }

    public function testMissingCounterFailsClosedOutsideDevelopment(): void
    {
        $middleware = new SocialLoginRateLimitMiddleware();
        $handler = static fn (Request $request): Response => new Response(200);

        try {
            $middleware->process($this->request('192.0.2.10'), $handler);
        } catch (RuntimeException $e) {
            $this->assertSame(ErrorCode::SERVICE_UNAVAILABLE->value, $e->getCode());
            return;
        }

        $this->fail('Expected the rate limiter to fail closed without a counter');
    }

    public function testFailingCounterFailsClosedOutsideDevelopment(): void
    {
        $middleware = new SocialLoginRateLimitMiddleware(
            counter: static function (): int {
                throw new RuntimeException('redis unavailable');
            },
        );
        $handler = static fn (Request $request): Response => new Response(200);

        try {
            $middleware->process($this->request('192.0.2.10'), $handler);
        } catch (RuntimeException $e) {
            $this->assertSame(ErrorCode::SERVICE_UNAVAILABLE->value, $e->getCode());
            return;
        }

        $this->fail('Expected the rate limiter to fail closed when the counter throws');
    }

    public function testDevelopmentEnvironmentBypassesTheLimiterWhenTheCounterIsUnavailable(): void
    {
        $middleware = new SocialLoginRateLimitMiddleware(environment: 'development');
        $handler = static fn (Request $request): Response => new Response(200);

        $response = $middleware->process($this->request('192.0.2.10'), $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testEnvironmentComesFromTheConstructorInsteadOfTheProcessEnvironment(): void
    {
        $previous = getenv('APP_ENV');
        putenv('APP_ENV=development');

        try {
            $middleware = new SocialLoginRateLimitMiddleware();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionCode(ErrorCode::SERVICE_UNAVAILABLE->value);
            $middleware->process(
                $this->request('192.0.2.10'),
                static fn (Request $request): Response => new Response(200),
            );
        } finally {
            $previous === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previous);
        }
    }

    /**
     * A counter that tallies every key it sees, so tests can assert on the
     * exact bucket layout and per-bucket counts.
     *
     * @param array<string, int> $keys
     */
    private function recordingCounter(array &$keys): \Closure
    {
        return static function (string $key) use (&$keys): int {
            return $keys[$key] = ($keys[$key] ?? 0) + 1;
        };
    }

    private function deviceKey(string $ip, string $deviceId): string
    {
        return 'auth:gateway:social-login:device:' . hash('sha256', $ip . '|' . $deviceId);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $post
     * @param array<string, mixed>|null $authContext
     */
    private function request(
        string $remoteIp,
        array $headers = [],
        array $post = [],
        ?array $authContext = null,
    ): Request {
        return new class ($remoteIp, $headers, $post, $authContext) extends Request {
            /** @param array<string, string> $headers */
            public function __construct(
                private readonly string $remoteIp,
                private readonly array $headers,
                private readonly array $post,
                public ?array $authContext,
            ) {
                parent::__construct('');
            }

            public function getRemoteIp(): string
            {
                return $this->remoteIp;
            }

            public function header(?string $name = null, mixed $default = null): mixed
            {
                if ($name === null) {
                    return $this->headers;
                }

                return $this->headers[strtolower($name)] ?? $default;
            }

            public function post(?string $name = null, mixed $default = null): mixed
            {
                return $name === null ? $this->post : ($this->post[$name] ?? $default);
            }
        };
    }
}
