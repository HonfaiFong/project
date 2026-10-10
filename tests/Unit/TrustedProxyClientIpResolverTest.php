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
        // CDN 边缘 100.64.0.0/10 -> 二层负载均衡 10.0.0.0/8 -> 网关；转发链为“client, cdn-edge”，连接对端为负载均衡器。
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

        // 攻击者在前方添加诱饵公网 IP，CDN 追加真实客户端 IP，因此仍使用最右侧不可信地址。
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

        // 客户端自行发送“X-Forwarded-For: 10.9.9.9”，可信 CDN 追加客户端真实地址；解析后的键仍应是真实地址。
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
        // 双栈负载均衡器提供 ::ffff:10.1.0.1 时，仍须视为已配置的 10.0.0.0/8 代理，否则其后的所有客户端会落入同一连接对
        // 端限流桶。
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
        // inet_pton 将这些表示解析为相同的 16 字节；基于前缀的规范化器此前只处理小写点分形式，会将其他形式的同一可信负载
        // 均衡器误判为不可信。
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
