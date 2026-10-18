<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

final class MetricsLifecycleTest extends TestCase
{
    public function testMetricsListenerExportsAndStopsAfterParentCrash(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Requires the Linux production runtime');
        }
        $reservation = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($reservation);
        $address = (string)stream_socket_get_name($reservation, false);
        fclose($reservation);
        $port = (int)substr($address, strrpos($address, ':') + 1);
        $directory = sys_get_temp_dir() . '/metrics-lifecycle-' . bin2hex(random_bytes(6));
        $script = <<<'PHP'
require $argv[1];
putenv('OBSERVABILITY_METRICS_DIR=' . $argv[3]);
putenv('SERVICE_NAME=lifecycle');
\Packages\Observability\MetricsRegistry::instance()->inc('lifecycle_probe_total');
\Packages\Observability\MetricsHttpServer::startInBackground((int)$argv[2]);
fwrite(STDOUT, "ready\n");
sleep(30);
PHP;
        $process = proc_open([PHP_BINARY, '-r', $script, dirname(__DIR__, 2) . '/vendor/autoload.php', (string)$port, $directory], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            self::assertSame("ready\n", fgets($pipes[1]));
            $body = false;
            for ($attempt = 0; $attempt < 30; $attempt++) {
                $body = @file_get_contents('http://' . $address . '/metrics');
                if ($body !== false) {
                    break;
                }
                usleep(100_000);
            }
            self::assertIsString($body);
            self::assertStringContainsString('lifecycle_probe_total', $body);
            proc_terminate($process, 9);
            proc_close($process);
            $process = null;
            $connection = false;
            for ($attempt = 0; $attempt < 30; $attempt++) {
                $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                if ($connection === false) {
                    break;
                }
                fclose($connection);
                usleep(100_000);
            }
            self::assertFalse($connection, 'Metrics child must not survive worker recycle');
        } finally {
            if (is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($directory);
        }
    }
}
