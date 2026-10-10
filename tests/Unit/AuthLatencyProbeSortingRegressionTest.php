<?php

declare(strict_types=1);

namespace Tests\Unit;

use RuntimeException;
use Tests\TestCase;

/**
 * Regression guard for scripts/auth_latency_probe.sh: the printed P50/P95/P99
 * report and the P95 budget gate must be computed from the same sorted sample,
 * so the verdict stays identical no matter which attempt happened to finish
 * last.
 *
 * curl is shadowed by a stub first in PATH (no network is touched); the stub
 * answers with the same "HTTP_CODE TIME_TOTAL" contract the probe parses, so
 * the whole script — loop, business-code check, report and gate — runs for
 * real against a controlled latency sample.
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
     * Runs the real probe script with a stubbed curl first in PATH and a fixed
     * latency sample; the network is never touched.
     *
     * @param  list<string> $timingsSeconds
     * @param  string       $httpCode    HTTP status answered by the curl stub
     * @param  string       $body        Body written by the curl stub to -o
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
            # Test double for curl, installed first in PATH by the regression
            # test: answers with the "HTTP_CODE TIME_TOTAL" contract the latency
            # probe parses, writes the configured body to the -o target and
            # never touches the network. Status/body come from
            # FAKE_CURL_HTTP_CODE / FAKE_CURL_BODY; the Nth call reports the
            # Nth latency from FAKE_CURL_TIMINGS.
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
