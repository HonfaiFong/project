<?php

declare(strict_types=1);

namespace Packages\Observability;

/**
 * Tiny read-only metrics listener for RoadRunner-only processes.
 *
 * RoadRunner's PHP worker is a gRPC worker and does not expose an HTTP plugin
 * in the current stack. A short-lived fork serves a scrape endpoint while
 * workers persist their registry snapshots to a per-PID file. This keeps the
 * application server wiring independent from compose and lets Prometheus
 * scrape each service on the same internal port.
 */
final class MetricsHttpServer
{
    public static function startInBackground(?int $port = null): void
    {
        if (!function_exists('pcntl_fork')) {
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

        $pid = @pcntl_fork();
        if ($pid !== 0) {
            return;
        }

        self::serve($port, $directory);
        exit(0);
    }

    private static function serve(int $port, string $directory): void
    {
        $errno = 0;
        $error = '';
        $server = @stream_socket_server('tcp://0.0.0.0:' . $port, $errno, $error);
        if (!is_resource($server)) {
            return;
        }
        stream_set_blocking($server, true);

        for (;;) {
            $connection = @stream_socket_accept($server, -1);
            if (!is_resource($connection)) {
                continue;
            }
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
