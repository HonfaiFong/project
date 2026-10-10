<?php

declare(strict_types=1);

namespace Tests\Unit;

use RuntimeException;
use Tests\TestCase;

/**
 * scripts/auth_latency_probe.sh 的回归检查：输出的 P50/P95/P99 报告和 P95 预算门槛须根据同一排序样本计算，确保判
 * 定不受最后完成的请求影响。
 *
 * 用 PATH 首位的桩替代 curl，不访问网络；桩返回探测所解析的“HTTP_CODE TIME_TOTAL”格式，使整个脚本（循环、业务代
 * 码检查、报告和预算门槛）针对可控延迟样本实际执行。
 */
final class AuthLatencyProbeSortingRegressionTest extends TestCase
{
    private string $dir = '';
    private string $curlStubDir = '';
    private string $timingsFile = '';
    private string $counterFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/auth-latency-probe-test-' . bin2hex(random_bytes(6));
        $this->curlStubDir = $this->dir . '/bin';
        $this->timingsFile = $this->dir . '/timings';
        $this->counterFile = $this->dir . '/curl-calls';

        if (!mkdir($this->curlStubDir, 0777, true) && !is_dir($this->curlStubDir)) {
            throw new RuntimeException('Cannot create probe test fixture dir: ' . $this->curlStubDir);
        }

        file_put_contents($this->curlStubDir . '/curl', $this->curlStub());
        chmod($this->curlStubDir . '/curl', 0755);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            foreach (glob($this->curlStubDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->curlStubDir);
            @unlink($this->timingsFile);
            @unlink($this->counterFile);
            @rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function testNineSlowAttemptsFollowedByOneFastAttemptStillFailsTheGate(): void
    {
        $result = $this->runProbe([
            '6.0', '6.0', '6.0', '6.0', '6.0', '6.0', '6.0', '6.0', '6.0', '1.0',
        ]);

        self::assertSame(1, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('p50 = 6000.0 ms', $result['stdout']);
        self::assertStringContainsString('p95 = 6000.0 ms', $result['stdout']);
        self::assertStringContainsString('FAILED: p95 6000.0 ms exceeds the 5000 ms budget', $result['stderr']);
        self::assertSame('10', trim((string) file_get_contents($this->counterFile)));
    }

    public function testTheSameSlowSampleInReverseOrderFailsIdentically(): void
    {
        $result = $this->runProbe([
            '1.0', '6.0', '6.0', '6.0', '6.0', '6.0', '6.0', '6.0', '6.0', '6.0',
        ]);

        self::assertSame(1, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('p95 = 6000.0 ms', $result['stdout']);
        self::assertStringContainsString('FAILED: p95 6000.0 ms exceeds the 5000 ms budget', $result['stderr']);
    }

    public function testALowLatencySamplePassesTheGate(): void
    {
        $result = $this->runProbe(array_fill(0, 10, '0.1'));

        self::assertSame(0, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertSame('', $result['stderr']);
        self::assertStringContainsString('p50 = 100.0 ms', $result['stdout']);
        self::assertStringContainsString('p95 = 100.0 ms', $result['stdout']);
        self::assertStringContainsString('p99 = 100.0 ms', $result['stdout']);
        self::assertSame('10', trim((string) file_get_contents($this->counterFile)));
    }

    public function testHttpErrorResponsesNeverLeakTheResponseBody(): void
    {
        $result = $this->runProbe(
            ['0.1'],
            '500',
            '{"code":0,"access_token":"PROBE-ACCESS-TOKEN-SENTINEL","refresh_token":"PROBE-REFRESH-TOKEN-SENTINEL"}',
        );

        self::assertSame(1, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('FAILED: probe login 1 returned HTTP 500', $result['stderr']);
        self::assertStringNotContainsString('PROBE-ACCESS-TOKEN-SENTINEL', $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('PROBE-REFRESH-TOKEN-SENTINEL', $result['stdout'] . $result['stderr']);
    }

    public function testNonNumericBusinessCodesNeverLeakTheResponseBody(): void
    {
        $result = $this->runProbe(['0.1'], '200', '{"code":"PROBE-CODE-SENTINEL"}');

        self::assertSame(1, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('FAILED: probe login 1 returned business code invalid', $result['stderr']);
        self::assertStringNotContainsString('PROBE-CODE-SENTINEL', $result['stdout'] . $result['stderr']);
    }

    public function testNumericStringBusinessCodesAreStillReported(): void
    {
        $result = $this->runProbe(['0.1'], '200', '{"code":"1001001"}');

        self::assertSame(1, $result['exit'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('FAILED: probe login 1 returned business code 1001001', $result['stderr']);
    }

    /**
     * 使用 PATH 首位的 curl 桩及固定延迟样本执行真实探测脚本，不访问网络。
     *
     * @param  list<string> $timingsSeconds
     * @param  string       $httpCode    curl 桩返回的 HTTP 状态
     * @param  string       $body        curl 桩写入 -o 目标的响应体
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runProbe(array $timingsSeconds, string $httpCode = '200', string $body = '{"code":0}'): array
    {
        file_put_contents($this->timingsFile, implode("\n", $timingsSeconds) . "\n");
        @unlink($this->counterFile);

        $environment = getenv();
        $path = $this->curlStubDir . PATH_SEPARATOR . dirname(PHP_BINARY);
        if (($environment['PATH'] ?? '') !== '') {
            $path .= PATH_SEPARATOR . $environment['PATH'];
        }
        $environment['PATH'] = $path;
        $environment['LC_ALL'] = 'C';
        $environment['AUTH_LATENCY_REQUESTS'] = (string) count($timingsSeconds);
        $environment['AUTH_LATENCY_BUDGET_MS'] = '5000';
        $environment['FAKE_CURL_TIMINGS'] = $this->timingsFile;
        $environment['FAKE_CURL_COUNTER'] = $this->counterFile;
        $environment['FAKE_CURL_HTTP_CODE'] = $httpCode;
        $environment['FAKE_CURL_BODY'] = $body;

        $process = proc_open(
            ['/bin/bash', dirname(__DIR__, 2) . '/scripts/auth_latency_probe.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot spawn scripts/auth_latency_probe.sh');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private function curlStub(): string
    {
        return <<<'SH'
            #!/bin/bash
            # curl 测试替身，由回归测试放在 PATH 首位；返回延迟
            # 探测解析的“HTTP_CODE TIME_TOTAL”格式，将配置的响应体
            # 写入 -o 目标，不访问网络。状态和响应体来自
            # FAKE_CURL_HTTP_CODE / FAKE_CURL_BODY；第 N 次调用
            # 返回 FAKE_CURL_TIMINGS 中的第 N 个延迟值。
            # 所有输出均由测试配置控制。
            set -euo pipefail

            body_file=""
            while [[ $# -gt 0 ]]; do
              case "$1" in
                -o|--output) body_file="$2"; shift 2 ;;
                -w|--write-out|-X|--request|-H|--header|-d|--data|--data-raw|--data-binary|--max-time|--connect-timeout|--retry) shift 2 ;;
                *) shift ;;
              esac
            done

            calls=1
            if [[ -f "$FAKE_CURL_COUNTER" ]]; then
              calls=$(( $(cat "$FAKE_CURL_COUNTER") + 1 ))
            fi
            printf '%s' "$calls" > "$FAKE_CURL_COUNTER"

            if [[ -n "$body_file" ]]; then
              printf '%s' "$FAKE_CURL_BODY" > "$body_file"
            fi

            printf '%s %s\n' "$FAKE_CURL_HTTP_CODE" "$(sed -n "${calls}p" "$FAKE_CURL_TIMINGS")"
            SH;
    }
}
