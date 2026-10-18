<?php

declare(strict_types=1);

// Runs in a separate PHP process with no application DB/Redis descriptors.
// Datagrams preserve message boundaries, including when the queue is full.
$retryAt = 0.0;
while (function_exists('posix_getppid') && posix_getppid() === (int)($argv[1] ?? 0)) {
    $read = [STDIN];
    $write = $except = null;
    if (stream_select($read, $write, $except, 1) !== 1) {
        continue;
    }
    $message = stream_socket_recvfrom(STDIN, 65_536);
    if ($message === false || $message === '') {
        break;
    }
    if (microtime(true) < $retryAt) {
        continue;
    }
    $data = json_decode($message, true);
    if (!is_array($data) || !is_string($data['endpoint'] ?? null)
        || !is_string($data['payload'] ?? null) || !is_array($data['headers'] ?? null)
        || !is_numeric($data['timeout'] ?? null) || !function_exists('curl_init')) {
        continue;
    }
    $handle = curl_init($data['endpoint']);
    if ($handle === false) {
        continue;
    }
    $timeout = max(1, (int)((float)$data['timeout'] * 1000));
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data['payload'],
        CURLOPT_HTTPHEADER => $data['headers'],
        CURLOPT_CONNECTTIMEOUT_MS => $timeout,
        CURLOPT_TIMEOUT_MS => $timeout,
        // Discard responses without buffering an unbounded collector body.
        CURLOPT_WRITEFUNCTION => static fn ($curl, string $body): int => strlen($body),
    ]);
    $ok = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($ok === false || $status < 200 || $status >= 300) {
        $retryAt = microtime(true) + 1.0;
    }
}
