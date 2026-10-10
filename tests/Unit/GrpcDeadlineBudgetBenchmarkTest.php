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
 * 针对 gRPC 截止时间预算的预热路径基准测试。
 *
 * 预算计算使用 compose.yaml / .env.example 的默认值：网关为认证调用提供 GRPC_AUTH_TIMEOUT_MS = 300 毫秒，
 * auth->user 用户目录调用最多消耗 GRPC_USER_DIRECTORY_TIMEOUT_MS = 250 毫秒，因此认证工作进程每次调用约有 50 毫
 * 秒可用于自身工作。启动时预热 Eloquent、Protobuf 描述符池及 JWT 编解码器，是为了将每次调用工作控制在亚毫秒范围；
 * 此处退化会使部署后的首次登录返回 503 Deadline Exceeded。
 *
 * 基准测量其中类似编解码的工作：一次 JWT 编解码、每个预热 Protobuf 消息的一次序列化及四次可信代理解析。阈值为较
 * 慢的 CI 运行器留出充足余量，同时仍能识别初始化开销量级的性能退化。
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
