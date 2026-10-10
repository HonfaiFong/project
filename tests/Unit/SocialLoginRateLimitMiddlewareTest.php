<?php

declare(strict_types=1);

namespace Tests\Unit;

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
        $count = 0;
        $middleware = new SocialLoginRateLimitMiddleware(
            counter: static function () use (&$count): int {
                $count++;

                return $count;
            },
            limit: 2,
            windowSeconds: 60,
            environment: 'development',
        );
        $request = new class ('') extends Request {
            public function getRemoteIp(): string
            {
                return '192.0.2.10';
            }
        };

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

    public function testTrustedProxyForwardedClientsGetSeparateBuckets(): void
    {
        $keys = [];
        $middleware = new SocialLoginRateLimitMiddleware(
            counter: static function (string $key) use (&$keys): int {
                $keys[] = $key;

                return 1;
            },
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

        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
    }

    public function testDualCdnLbChainKeepsDistinctClientBuckets(): void
    {
        // Two clients arrive through the same dual CDN -> LB chain; they must
        // never share one bucket keyed on the shared proxy infrastructure.
        $keys = [];
        $middleware = new SocialLoginRateLimitMiddleware(
            counter: static function (string $key) use (&$keys): int {
                $keys[] = $key;

                return 1;
            },
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

        $this->assertSame(
            'auth:gateway:social-login:' . hash('sha256', '198.51.100.10'),
            $keys[0]
        );
        $this->assertSame(
            'auth:gateway:social-login:' . hash('sha256', '198.51.100.11'),
            $keys[1]
        );
    }

    public function testIpv4MappedDualStackChainKeysOnTheRealClient(): void
    {
        $keys = [];
        $middleware = new SocialLoginRateLimitMiddleware(
            counter: static function (string $key) use (&$keys): int {
                $keys[] = $key;

                return 1;
            },
            trustedProxyCidrs: ['10.0.0.0/8'],
            environment: 'development',
        );
        $handler = static fn (Request $request): Response => new Response(200);

        $middleware->process(
            $this->request('::ffff:10.1.0.1', ['x-forwarded-for' => '::ffff:198.51.100.10']),
            $handler,
        );

        $this->assertSame(
            'auth:gateway:social-login:' . hash('sha256', '198.51.100.10'),
            $keys[0]
        );
    }

    public function testIpv6ClientsShareOneBucketPerSlash64(): void
    {
        $keys = [];
        $middleware = new SocialLoginRateLimitMiddleware(
            counter: static function (string $key) use (&$keys): int {
                $keys[] = $key;

                return 1;
            },
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

        $this->assertSame(
            'auth:gateway:social-login:' . hash('sha256', '2001:db8:aaaa:1::/64'),
            $keys[0]
        );
        $this->assertSame($keys[0], $keys[1]);
        $this->assertNotSame($keys[0], $keys[2]);
    }

    public function testUntrustedPeerCannotChooseBucketWithForwardedHeader(): void
    {
        $keys = [];
        $middleware = new SocialLoginRateLimitMiddleware(
            counter: static function (string $key) use (&$keys): int {
                $keys[] = $key;

                return 1;
            },
            trustedProxyCidrs: ['10.0.0.0/8'],
            environment: 'development',
        );
        $handler = static fn (Request $request): Response => new Response(200);

        $middleware->process(
            $this->request('198.51.100.50', ['x-forwarded-for' => '203.0.113.10']),
            $handler,
        );

        $this->assertSame(
            'auth:gateway:social-login:' . hash('sha256', '198.51.100.50'),
            $keys[0]
        );
    }

    public function testMalformedForwardedHopFallsBackToSocketPeer(): void
    {
        $keys = [];
        $middleware = new SocialLoginRateLimitMiddleware(
            counter: static function (string $key) use (&$keys): int {
                $keys[] = $key;

                return 1;
            },
            trustedProxyCidrs: ['10.0.0.0/8'],
            environment: 'development',
        );
        $handler = static fn (Request $request): Response => new Response(200);

        $middleware->process(
            $this->request('10.1.0.1', ['x-forwarded-for' => '198.51.100.10, 10.2.0.1, forged']),
            $handler,
        );

        $this->assertSame(
            'auth:gateway:social-login:' . hash('sha256', '10.1.0.1'),
            $keys[0]
        );
    }

    public function testInvalidTrustedProxyCidrFailsClosedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SocialLoginRateLimitMiddleware(trustedProxyCidrs: ['not-a-cidr']);
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

    private function request(string $remoteIp, array $headers = []): Request
    {
        return new class ($remoteIp, $headers) extends Request {
            /** @param array<string, string> $headers */
            public function __construct(
                private readonly string $remoteIp,
                private readonly array $headers,
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
        };
    }
}
