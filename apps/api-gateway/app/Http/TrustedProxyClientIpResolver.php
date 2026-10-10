<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Http;

use InvalidArgumentException;
use Webman\Http\Request;

final class TrustedProxyClientIpResolver
{
    /**
     * @var list<array{network: string, prefix: int, bytes: int}>
     */
    private readonly array $trustedProxyNetworks;

    /**
     * @param list<string> $trustedProxyCidrs
     */
    public function __construct(array $trustedProxyCidrs = [])
    {
        $this->trustedProxyNetworks = $this->parseTrustedProxyCidrs($trustedProxyCidrs);
    }

    public function resolve(Request $request): string
    {
        $peer = $this->normalizeIp(trim((string)$request->getRemoteIp()));
        if (filter_var($peer, FILTER_VALIDATE_IP) === false) {
            return 'unknown';
        }

        // 仅当直接 socket 对端落在显式配置的可信代理网段内时，才接受转发头。
        if (!$this->isTrustedProxy($peer)) {
            return $peer;
        }

        $forwarded = $request->header('x-forwarded-for');
        if (blank($forwarded)) {
            return $peer;
        }

        $chain = [];
        foreach (explode(',', $forwarded) as $candidate) {
            $candidate = $this->normalizeIp(trim($candidate));
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                return $peer;
            }

            $chain[] = $candidate;
        }

        // 从可信网关一侧向客户端方向回溯，链上第一个不在可信范围内的地址即客户端地址。
        $chain[] = $peer;
        for ($index = count($chain) - 1; $index >= 0; $index--) {
            if (!$this->isTrustedProxy($chain[$index])) {
                return $chain[$index];
            }
        }

        return $peer;
    }

    /**
     * IPv4 映射的 IPv6 地址（::ffff:198.51.100.10）必须按其内嵌的 IPv4 形式比较：
     * 双栈 CDN/LB 可能在一跳呈现映射形式、在另一跳呈现普通形式，一旦不一致就会
     * 把可信一跳静默降级为不可信（所有客户端塌缩进同一个 socket 对端限流桶），
     * 或把同一个客户端拆到两个 key 上。
     *
     * 判断必须是二进制而非文本比较：inet_pton 接受同一映射地址的多种写法
     * （::ffff:10.1.0.1、::FFFF:10.1.0.1、::ffff:a01:1、0:0:0:0:0:ffff:a01:1），
     * 只有 packed 形式的 96 位零加 ffff 前缀才算数——用 str_starts_with('::ffff:')
     * 匹配会漏掉其他写法，再次破坏可信一跳的判定。
     */
    private function normalizeIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $ip;
        }

        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }
        if (substr($packed, 0, 12) !== "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            return $ip;
        }

        $embedded = inet_ntop(substr($packed, 12));
        if ($embedded === false) {
            return $ip;
        }

        return $embedded;
    }

    private function isTrustedProxy(string $ip): bool
    {
        $packedIp = inet_pton($ip);
        if ($packedIp === false) {
            return false;
        }

        foreach ($this->trustedProxyNetworks as $network) {
            if (strlen($packedIp) !== $network['bytes']) {
                continue;
            }

            $fullBytes = intdiv($network['prefix'], 8);
            if ($fullBytes > 0 && substr($packedIp, 0, $fullBytes) !== substr($network['network'], 0, $fullBytes)) {
                continue;
            }

            $remainingBits = $network['prefix'] % 8;
            if ($remainingBits === 0) {
                return true;
            }

            $mask = (0xff << (8 - $remainingBits)) & 0xff;
            if ((ord($packedIp[$fullBytes]) & $mask) === (ord($network['network'][$fullBytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $cidrs
     * @return list<array{network: string, prefix: int, bytes: int}>
     *
     * Webman 自带的 getRealIp() 策略信任所有内网对端，且接受的请求头超出了
     * 本网关的代理契约范围。这里用原生 inet_pton 前缀比较把可信代理边界显式化。
     */
    private function parseTrustedProxyCidrs(array $cidrs): array
    {
        $networks = [];
        foreach ($cidrs as $cidr) {
            $cidr = trim($cidr);
            if (blank($cidr)) {
                continue;
            }

            $parts = explode('/', $cidr, 2);
            $network = inet_pton($parts[0]);
            if ($network === false) {
                throw new InvalidArgumentException('Invalid trusted proxy CIDR: ' . $cidr);
            }

            $bytes = strlen($network);
            $maxPrefix = $bytes * 8;
            $prefix = $parts[1] ?? (string)$maxPrefix;
            if (!ctype_digit($prefix) || (int)$prefix < 1 || (int)$prefix > $maxPrefix) {
                throw new InvalidArgumentException('Invalid trusted proxy CIDR: ' . $cidr);
            }

            $networks[] = [
                'network' => $network,
                'prefix'  => (int)$prefix,
                'bytes'   => $bytes,
            ];
        }

        return $networks;
    }
}
