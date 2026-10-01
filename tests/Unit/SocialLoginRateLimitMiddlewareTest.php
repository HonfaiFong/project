<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Middleware\SocialLoginRateLimitMiddleware;
use Contracts\Errors\ErrorCode;
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
            counter: static function (string $key, int $ttl) use (&$count): int {
                $count++;

                return $count;
            },
            limit: 2,
            windowSeconds: 60,
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
}
