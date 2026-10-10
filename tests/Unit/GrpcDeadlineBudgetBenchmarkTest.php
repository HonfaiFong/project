<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Http\TrustedProxyClientIpResolver;
use Contracts\Gen\Auth\V1\BindSocialIdentityRequest;
use Contracts\Gen\Auth\V1\LoginRequest;
use Contracts\Gen\Auth\V1\SocialLoginRequest;
use Contracts\Gen\Auth\V1\VerifyTokenRequest;
use Services\AuthService\Infrastructure\Token\JwtTokenCodec;
use Tests\TestCase;
use Webman\Http\Request;

/**
 * Warm-path benchmark against the gRPC deadline budget.
 *
 * Budget arithmetic (defaults shipped in compose.yaml / .env.example): the
 * gateway gives auth calls GRPC_AUTH_TIMEOUT_MS = 300 ms, and the auth->user
 * directory hop consumes up to GRPC_USER_DIRECTORY_TIMEOUT_MS = 250 ms, so the
 * auth worker has roughly 50 ms of its own per call. The worker bootstraps
 * Eloquent, the protobuf pool, and the JWT codec precisely so that this
 * per-call work stays in the sub-millisecond warm range; a regression here is
 * what turns "first login after deploy" into a 503 Deadline Exceeded.
 *
 * The benchmark measures the codec-shaped share of that work: one JWT
 * encode+decode, one serialization of each warmed protobuf message, and four
 * trusted-proxy resolutions. The threshold keeps a large safety margin for
 * slow CI runners while still failing on boot-cost-scale regressions.
 */
final class GrpcDeadlineBudgetBenchmarkTest extends TestCase
{
    private const WARMUP_ITERATIONS = 30;
    private const MEASURED_ITERATIONS = 300;
    private const AUTH_SIDE_BUDGET_MS = 50;
    private const MAX_WARM_MEAN_MS = 10;

    public function testWarmPerCallCodecWorkStaysFarBelowTheAuthSideDeadlineBudget(): void
    {
        $codec = new JwtTokenCodec('benchmark-secret-at-least-32-chars-long');
        $resolver = new TrustedProxyClientIpResolver(['100.64.0.0/10', '10.0.0.0/8']);
        $chainRequest = $this->dualChainRequest();

        $iteration = static function () use ($codec, $resolver, $chainRequest): void {
            $now = time();
            $token = $codec->encode(['sub' => 'bench', 'device_id' => 'bench', 'iat' => $now, 'exp' => $now + 60]);
            $codec->decode($token);
            (new LoginRequest(['phone' => '+8613800000000']))->serializeToString();
            (new SocialLoginRequest(['code' => 'bench']))->serializeToString();
            (new VerifyTokenRequest(['access_token' => 'bench']))->serializeToString();
            (new BindSocialIdentityRequest(['code' => 'bench']))->serializeToString();
            for ($i = 0; $i < 4; $i++) {
                $resolver->resolve($chainRequest);
            }
        };

        for ($i = 0; $i < self::WARMUP_ITERATIONS; $i++) {
            $iteration();
        }

        $start = hrtime(true);
        for ($i = 0; $i < self::MEASURED_ITERATIONS; $i++) {
            $iteration();
        }
        $elapsedMs = (hrtime(true) - $start) / 1e6;
        $meanMs = $elapsedMs / self::MEASURED_ITERATIONS;

        $this->assertLessThan(
            self::MAX_WARM_MEAN_MS,
            $meanMs,
            sprintf(
                'Warm per-call codec work averages %.3f ms and must stay far below the %d ms auth-side budget '
                . '(gateway 300 ms outer deadline minus 250 ms directory hop); a cold-path dependency '
                . 'probably leaked into the warm path',
                $meanMs,
                self::AUTH_SIDE_BUDGET_MS,
            )
        );
    }

    private function dualChainRequest(): Request
    {
        return new class ('10.2.0.7', ['x-forwarded-for' => '198.51.100.10, 100.64.1.9']) extends Request {
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
