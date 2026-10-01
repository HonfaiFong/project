<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Middleware;

use Closure;
use Contracts\Errors\ErrorCode;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class SocialLoginRateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @var list<array{network: string, prefix: int, bytes: int}>
     */
    private readonly array $trustedProxyNetworks;

    /**
     * @param Closure(string, int): int|null $counter
     * @param list<string> $trustedProxyCidrs
     */
    public function __construct(
        private readonly ?Closure $counter = null,
        private readonly int $limit = 30,
        private readonly int $windowSeconds = 60,
        array $trustedProxyCidrs = [],
    ) {
        if ($this->limit < 1 || $this->windowSeconds < 1) {
            throw new InvalidArgumentException('Social login rate limit settings must be positive');
        }

        $this->trustedProxyNetworks = $this->parseTrustedProxyCidrs($trustedProxyCidrs);
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
            $key = 'auth:gateway:social-login:' . hash('sha256', $this->clientIp($request));
            $count = ($this->counter)($key, $this->windowSeconds);
        } catch (Throwable $e) {
            if ($this->isProduction()) {
                throw new RuntimeException(
                    'Social login rate limiter is unavailable',
                    ErrorCode::SERVICE_UNAVAILABLE->value,
                    $e
                );
            }

            return $handler($request);
        }

        if ($count > $this->limit) {
            throw new RuntimeException(
                ErrorCode::RATE_LIMIT_EXCEEDED->message(),
                ErrorCode::RATE_LIMIT_EXCEEDED->value
            );
        }

        return $handler($request);
    }

    private function clientIp(Request $request): string
    {
        $peer = trim((string)$request->getRemoteIp());
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
        $hasMalformedHop = false;
        foreach (explode(',', $forwarded) as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                $chain[] = $candidate;
                continue;
            }

            $hasMalformedHop = true;
            break;
        }
        if ($hasMalformedHop) {
            return $peer;
        }

        // Walk from the trusted gateway towards the client. The first address
        // outside the trusted chain is the client key. A malformed hop falls
        // back to the socket peer above instead of changing the trust boundary.
        $chain[] = $peer;
        for ($index = count($chain) - 1; $index >= 0; $index--) {
            if (!$this->isTrustedProxy($chain[$index])) {
                return $chain[$index];
            }
        }

        return $peer;
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
     * Webman only offers a generic intranet-based getRealIp() policy, and this
     * project does not depend on Symfony HttpFoundation/IpUtils. The native
     * inet_pton prefix check keeps the trusted proxy boundary explicit.
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

    private function isProduction(): bool
    {
        $environment = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));

        return !in_array($environment, ['local', 'development', 'dev', 'testing'], true);
    }
}
