<?php

declare(strict_types=1);

namespace Tests\Integration;

use Packages\Observability\OtlpHttpExporter;
use Packages\Observability\Tracer;
use PHPUnit\Framework\TestCase;

final class OtlpIsolationTest extends TestCase
{
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
            $body = '';
            $length = (int)$match[1];
            while (strlen($body) < $length) {
                $body .= (string)fread($connection, $length - strlen($body));
            }
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
}
