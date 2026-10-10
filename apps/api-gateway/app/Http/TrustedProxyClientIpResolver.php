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

        // Forwarded headers are accepted only when the immediate socket peer
        // is inside the explicitly configured trusted proxy ranges.
        if (!$this->isTrustedProxy($peer)) {
            return $peer;
        }

        $forwarded = $request->header('x-forwarded-for');
        if (!is_string($forwarded) || trim($forwarded) === '') {
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

        // Walk from the trusted gateway towards the client. The first address
        // outside the trusted chain is the client key.
        $chain[] = $peer;
        for ($index = count($chain) - 1; $index >= 0; $index--) {
            if (!$this->isTrustedProxy($chain[$index])) {
                return $chain[$index];
            }
        }

        return $peer;
    }

    /**
     * IPv4-mapped IPv6 addresses (::ffff:198.51.100.10) must compare as their
     * embedded IPv4 form: a dual-stack CDN/LB can present the mapped form on
     * one hop and the plain form on another, and a mismatch would silently
     * demote a trusted hop to untrusted (collapsing every client into one
     * shared socket-peer rate bucket) or split one client across two keys.
     */
    private function normalizeIp(string $ip): string
    {
        if (str_starts_with($ip, '::ffff:')) {
            $embedded = substr($ip, strlen('::ffff:'));
            if (filter_var($embedded, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $embedded;
            }
        }

        return $ip;
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
     * Webman's generic getRealIp() policy trusts all intranet peers and also
     * accepts headers that are not part of this gateway's proxy contract. The
     * native inet_pton prefix check keeps the trusted proxy boundary explicit.
     */
    private function parseTrustedProxyCidrs(array $cidrs): array
    {
        $networks = [];
        foreach ($cidrs as $cidr) {
            $cidr = trim($cidr);
            if ($cidr === '') {
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
