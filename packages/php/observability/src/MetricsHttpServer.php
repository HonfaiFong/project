<?php

declare(strict_types=1);

namespace Packages\Observability;

/**
 * Tiny read-only metrics listener for RoadRunner-only processes.
 *
 * RoadRunner's PHP worker is a gRPC worker and does not expose an HTTP plugin
 * in the current stack. A separate PHP process serves a scrape endpoint while
 * workers persist their registry snapshots to a per-PID file. This keeps the
 * application server wiring independent from compose and lets Prometheus
 * scrape each service on the same internal port.
 */
final class MetricsHttpServer
{
    public static function startInBackground(?int $port = null): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('proc_open') || !function_exists('posix_getppid')) {
            return;
        }
        $port ??= self::portFromEnvironment();
        if ($port < 1 || $port > 65535) {
            return;
        }

        $directory = trim((string)(getenv('OBSERVABILITY_METRICS_DIR') ?: ''));
        if ($directory === '') {
            $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'social-observability-metrics';
            putenv('OBSERVABILITY_METRICS_DIR=' . $directory);
        }
        MetricsRegistry::setPersistenceDirectory($directory);
        MetricsRegistry::instance()->persistNow();

        // Exec a clean runtime: forking a process with gRPC threads can hang
        // the child's extension shutdown, leaving zombies after worker recycle.
        $process = @proc_open(
            [PHP_BINARY, __DIR__ . '/metrics-worker.php', (string)getmypid(), (string)$port, $directory],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return;
        }
        register_shutdown_function(static function () use ($process): void {
            proc_terminate($process, 9);
            proc_close($process);
        });
    }

    /** @internal Entry point for the isolated metrics process. */
    public static function serve(int $port, string $directory, int $parentPid): void
    {
        $errno = 0;
        $error = '';
        // Other workers may already own the scrape port. Remain a standby
        // until it becomes free, so pool recycling cannot remove metrics.
        $server = false;
        while (posix_getppid() === $parentPid) {
            $server = @stream_socket_server('tcp://0.0.0.0:' . $port, $errno, $error);
            if (is_resource($server)) {
                break;
            }
            usleep(250_000);
        }
        if (!is_resource($server)) {
            return;
        }
        stream_set_blocking($server, true);

        while (posix_getppid() === $parentPid) {
            $connection = @stream_socket_accept($server, 1);
            if (!is_resource($connection)) {
                continue;
            }
            stream_set_timeout($connection, 1);
            $requestLine = fgets($connection) ?: '';
            while (($line = fgets($connection)) !== false && trim($line) !== '') {
            }
            $path = '/';
            if (preg_match('/\A[A-Z]+\s+(\S+)/', $requestLine, $matches) === 1) {
                $path = (string)parse_url($matches[1], PHP_URL_PATH);
            }
            if ($path === '/metrics') {
                $status = '200 OK';
                $body = MetricsRegistry::renderPersisted($directory);
            } else {
                $status = '404 Not Found';
                $body = "not found\n";
            }
            $response = "HTTP/1.1 {$status}\r\n"
                . "Content-Type: text/plain; version=0.0.4; charset=utf-8\r\n"
                . 'Content-Length: ' . strlen($body) . "\r\n"
                . "Connection: close\r\n\r\n"
                . $body;
            @fwrite($connection, $response);
            @fclose($connection);
        }
    }

    private static function portFromEnvironment(): int
    {
        $raw = trim((string)(getenv('OBSERVABILITY_METRICS_PORT') ?: '9100'));

        return ctype_digit($raw) ? (int)$raw : 9100;
    }
}
