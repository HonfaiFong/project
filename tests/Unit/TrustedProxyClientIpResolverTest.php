<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Http\TrustedProxyClientIpResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testDualCdnAndLbHopsInSeparateTrustedRangesResolveTheClient(): void
    {
        // CDN edge 100.64.0.0/10 -> L2 LB 10.0.0.0/8 -> gateway. The chain is
        // "client, cdn-edge" with the LB as the socket peer.
        $resolver = new TrustedProxyClientIpResolver(['100.64.0.0/10', '10.0.0.0/8']);

        $this->assertSame(
            '198.51.100.10',
            $resolver->resolve($this->request(
                '10.2.0.7',
                ['x-forwarded-for' => '198.51.100.10, 100.64.1.9']
            ))
        );
    }

    public function testLeftmostSpoofedHopsCannotShiftTheDualChainClientIp(): void
    {
        $resolver = new TrustedProxyClientIpResolver(['100.64.0.0/10', '10.0.0.0/8']);

        // The attacker prepends a decoy public IP; the CDN appends the real
        // client IP, so the rightmost untrusted address still wins.
        $this->assertSame(
            '198.51.100.10',
            $resolver->resolve($this->request(
                '10.2.0.7',
                ['x-forwarded-for' => '1.2.3.4, 198.51.100.10, 100.64.1.9']
            ))
        );
    }

    public function testIntruderCannotEnterTheChainByClaimingATrustedRange(): void
    {
        $resolver = new TrustedProxyClientIpResolver(['100.64.0.0/10', '10.0.0.0/8']);

        // The client sends "X-Forwarded-For: 10.9.9.9" itself; the trusted CDN
        // appends the client's real address, which stays the resolved key.
        $this->assertSame(
            '198.51.100.77',
            $resolver->resolve($this->request(
                '10.2.0.7',
                ['x-forwarded-for' => '10.9.9.9, 198.51.100.77, 100.64.1.9']
            ))
        );
    }

    public function testIpv4MappedIpv6PeerIsComparedAsItsEmbeddedAddress(): void
    {
        // A dual-stack LB presenting ::ffff:10.1.0.1 must still count as the
        // configured 10.0.0.0/8 proxy; otherwise every client behind it would
        // collapse into one shared socket-peer rate bucket.
        $resolver = new TrustedProxyClientIpResolver(['10.0.0.0/8']);

        $this->assertSame(
            '198.51.100.10',
            $resolver->resolve($this->request(
                '::ffff:10.1.0.1',
                ['x-forwarded-for' => '::ffff:198.51.100.10']
            ))
        );
    }

    #[DataProvider('ipv4MappedSpellings')]
    public function testEveryIpv4MappedTextualSpellingBehavesIdentically(string $spelling): void
    {
        // inet_pton parses all of these to the same 16 bytes; a prefix-based
        // normalizer only handled the dotted lowercase one and would demote
        // the same trusted LB to untrusted for the others.
        $resolver = new TrustedProxyClientIpResolver(['10.0.0.0/8']);

        $this->assertSame(
            '198.51.100.10',
            $resolver->resolve($this->request(
                $spelling,
                ['x-forwarded-for' => '198.51.100.10']
            )),
            $spelling . ' must normalize to the embedded 10.1.0.1 and stay a trusted proxy'
        );
    }

    /** @return list<array{string}> */
    public static function ipv4MappedSpellings(): array
    {
        return [
            ['::ffff:10.1.0.1'],
            ['::FFFF:10.1.0.1'],
            ['::ffff:a01:1'],
            ['0:0:0:0:0:ffff:a01:1'],
        ];
    }

    public function testNonMappedIpv6AddressesAreNotTouched(): void
    {
        $resolver = new TrustedProxyClientIpResolver(['fd00::/8']);

        $this->assertSame(
            '2001:db8::1',
            $resolver->resolve($this->request('fd00::1', ['x-forwarded-for' => '2001:db8::1']))
        );
    }

    public function testIpv6ClientIsResolvedThroughATrustedIpv6Proxy(): void
    {
        $resolver = new TrustedProxyClientIpResolver(['fd00::/8']);

        $this->assertSame(
            '2001:db8:abcd:12::42',
            $resolver->resolve($this->request(
                'fd00::1',
                ['x-forwarded-for' => '2001:db8:abcd:12::42, fd00::2']
            ))
        );
    }

    public function testAllTrustedChainFallsBackToTheSocketPeer(): void
    {
        $resolver = new TrustedProxyClientIpResolver(['10.0.0.0/8']);

        $this->assertSame(
            '10.2.0.7',
            $resolver->resolve($this->request(
                '10.2.0.7',
                ['x-forwarded-for' => '10.3.0.1, 10.4.0.1']
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
