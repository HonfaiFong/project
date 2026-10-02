<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Http\TrustedProxyClientIpResolver;
use InvalidArgumentException;
use Tests\TestCase;
use Webman\Http\Request;

final class TrustedProxyClientIpResolverTest extends TestCase
{
    public function testUntrustedPeerCannotChooseClientIpWithForwardedHeader(): void
    {
        $resolver = new TrustedProxyClientIpResolver(['10.0.0.0/8']);

        $this->assertSame(
            '198.51.100.50',
            $resolver->resolve($this->request('198.51.100.50', ['x-forwarded-for' => '203.0.113.10']))
        );
    }

    public function testTrustedProxyChainResolvesTheFirstUntrustedHop(): void
    {
        $resolver = new TrustedProxyClientIpResolver(['10.0.0.0/8']);

        $this->assertSame(
            '198.51.100.10',
            $resolver->resolve($this->request(
                '10.1.0.1',
                ['x-forwarded-for' => '198.51.100.10, 10.2.0.1']
            ))
        );
    }

    public function testMalformedForwardedChainFallsBackToSocketPeer(): void
    {
        $resolver = new TrustedProxyClientIpResolver(['10.0.0.0/8']);

        $this->assertSame(
            '10.1.0.1',
            $resolver->resolve($this->request(
                '10.1.0.1',
                ['x-forwarded-for' => '198.51.100.10, forged']
            ))
        );
    }

    public function testPublicAnyAddressTrustedProxyRangeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TrustedProxyClientIpResolver(['0.0.0.0/0']);
    }

    /** @param array<string, string> $headers */
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
