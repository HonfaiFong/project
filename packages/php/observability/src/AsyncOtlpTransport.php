<?php

declare(strict_types=1);

namespace Packages\Observability;

/** Bounded, non-blocking datagram queue to an isolated HTTP exporter process. */
final class AsyncOtlpTransport
{
    /** @var resource|null */
    private $socket = null;
    /** @var resource|null */
    private $process = null;

    public function __construct()
    {
        // Never fall back to synchronous networking on unsupported runtimes.
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('proc_open') || !function_exists('posix_getppid')) {
            return;
        }
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_DGRAM, 0);
        if ($pair === false) {
            return;
        }
        $null = '/dev/null';
        $process = @proc_open(
            [PHP_BINARY, __DIR__ . '/otlp-worker.php', (string)getmypid()],
            [0 => $pair[1], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
        );
        fclose($pair[1]);
        if (!is_resource($process)) {
            fclose($pair[0]);
            return;
        }
        stream_set_blocking($pair[0], false);
        $this->socket = $pair[0];
        $this->process = $process;
    }

    /** Queue saturation/oversized spans drop telemetry, never delay the caller. */
    public function send(string $message): void
    {
        if ($this->socket !== null && strlen($message) <= 60_000) {
            @stream_socket_sendto($this->socket, $message);
        }
    }

    public function __destruct()
    {
        if ($this->socket !== null) {
            fclose($this->socket);
        }
        if ($this->process !== null) {
            // Shutdown must not wait for DNS, HTTP, or the queued spans.
            proc_terminate($this->process, 9);
            proc_close($this->process);
        }
    }
}
