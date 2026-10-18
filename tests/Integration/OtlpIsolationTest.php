<?php

declare(strict_types=1);

namespace Tests\Integration;

use Packages\Observability\OtlpHttpExporter;
use Packages\Observability\Tracer;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class OtlpIsolationTest extends TestCase
{
    public function testBodyDeadlineRejectsACollectorSendingContinuousSmallChunks(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($listener);
        $process = null;
        $connection = null;
        $pipes = [];
        try {
            $script = <<<'PHP'
                $connection = stream_socket_client('tcp://' . $argv[1]);
                for ($i = 0; $i < 40; $i++) {
                    fwrite($connection, 'x');
                    usleep(100_000);
                }
                fclose($connection);
                PHP;
            $process = proc_open([PHP_BINARY, '-r', $script, stream_socket_get_name($listener, false)], [
                1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes);
            self::assertIsResource($process);
            $connection = stream_socket_accept($listener, 3);
            self::assertIsResource($connection);
            stream_set_timeout($connection, 2);
            $start = hrtime(true);
            $failure = null;
            try {
                $this->readBody($connection, 40);
            } catch (AssertionFailedError $e) {
                $failure = $e;
            }
            self::assertNotNull($failure, 'A continuous slow drip must not bypass the total body deadline');
            // The stream timeout may fire just before the monotonic deadline check.
            self::assertMatchesRegularExpression('/deadline|Timed out waiting for the OTLP request body/', $failure->getMessage());
            self::assertLessThan(2.75, (hrtime(true) - $start) / 1e9);
        } finally {
            if (is_resource($connection)) {
                fclose($connection);
            }
            if (is_resource($process)) {
                proc_terminate($process, 9);
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);
            }
            fclose($listener);
        }
    }

    public function testHungCollectorReceivesOtlpButCannotBlockRequestsOrShutdown(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('proc_open')) {
            self::markTestSkipped('Requires the Linux production runtime');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        $exporter = new OtlpHttpExporter('http://' . $address . '/v1/traces', 10.0, ['X-Test' => 'isolation']);
        Tracer::setExporter($exporter);
        try {
            $start = hrtime(true);
            Tracer::enter('isolated-span')->close();
            self::assertLessThan(0.5, (hrtime(true) - $start) / 1e9);
            $connection = stream_socket_accept($listener, 3);
            self::assertIsResource($connection, 'The background worker must actually export spans');
            stream_set_timeout($connection, 2);
            $headers = '';
            while (($line = fgets($connection)) !== false && trim($line) !== '') {
                $headers .= $line;
            }
            self::assertStringContainsString('POST /v1/traces', $headers);
            self::assertStringContainsString('X-Test: isolation', $headers);
            self::assertSame(1, preg_match('/Content-Length: (\d+)/i', $headers, $match));
            $length = (int)$match[1];
            $body = $this->readBody($connection, $length);
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('isolated-span', $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['name']);
            // Deliberately never respond. Fill the queue while HTTP is blocked.
            $start = hrtime(true);
            for ($i = 0; $i < 1000; $i++) {
                Tracer::enter('queue-saturation')->close();
            }
            Tracer::resetExporter();
            unset($exporter);
            self::assertLessThan(1.0, (hrtime(true) - $start) / 1e9);
            fclose($connection);
        } finally {
            Tracer::resetExporter();
            fclose($listener);
        }
    }

    public function testMissingCollectorDnsDoesNotConsumeRpcBudget(): void
    {
        Tracer::setExporter(new OtlpHttpExporter('http://missing-collector.invalid:4318/v1/traces', 10.0));
        $start = hrtime(true);
        try {
            for ($i = 0; $i < 30; $i++) {
                Tracer::enter('dns-unavailable')->close();
            }
        } finally {
            Tracer::resetExporter();
        }
        self::assertLessThan(0.5, (hrtime(true) - $start) / 1e9);
    }

    /**
     * 读取必须同时受 EOF、流超时和总截止时间约束：导出进程若发送部分数据后异常退出，
     * fread() 会持续返回空串，无退出条件的循环会让测试挂起而不是失败。
     *
     * @param resource $connection
     */
    private function readBody($connection, int $length): string
    {
        $body = '';
        $deadline = hrtime(true) / 1e9 + 2.0;
        while (strlen($body) < $length) {
            $remaining = $deadline - hrtime(true) / 1e9;
            self::assertGreaterThan(0.0, $remaining, 'OTLP request body did not arrive before the deadline');
            // Bound each blocking read by the remaining total budget, even for a slow drip.
            $timeoutMicros = max(1, (int)ceil($remaining * 1e6));
            stream_set_timeout($connection, intdiv($timeoutMicros, 1_000_000), $timeoutMicros % 1_000_000);
            $chunk = fread($connection, $length - strlen($body));
            self::assertLessThan($deadline, hrtime(true) / 1e9, 'OTLP request body did not arrive before the deadline');
            if ($chunk === false || $chunk === '') {
                $info = stream_get_meta_data($connection);
                self::assertFalse($info['timed_out'], 'Timed out waiting for the OTLP request body');
                self::assertFalse(feof($connection), 'OTLP exporter closed the connection before sending the full body');
                usleep(20_000);
                continue;
            }
            $body .= $chunk;
        }

        return $body;
    }
}
