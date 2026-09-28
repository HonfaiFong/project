<?php

declare(strict_types=1);

namespace Packages\Logging;

use Packages\Tracing\TraceContext;
use Stringable;

final class JsonLogger
{
    private const SENSITIVE_KEYS = [
        'password',
        'passwd',
        'token',
        'access_token',
        'refresh_token',
        'ws_ticket',
        'ticket',
        'secret',
        'private_key',
        'authorization',
    ];

    public function __construct(
        private readonly string $serviceName,
        private readonly string $instanceId = 'default',
        private readonly mixed $outputStream = null
    ) {
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log('DEBUG', (string)$message, $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log('INFO', (string)$message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log('WARN', (string)$message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log('ERROR', (string)$message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $trace = TraceContext::current();

        $entry = [
            'timestamp'  => gmdate('Y-m-d\TH:i:s.v\Z'),
            'level'      => strtoupper($level),
            'service'    => $this->serviceName,
            'instance'   => $this->instanceId,
            'trace_id'   => $trace->traceId,
            'request_id' => $trace->requestId,
            'user_id'    => $trace->userId ?? '',
            'message'    => $message,
            'context'    => $this->sanitizeContext($context),
        ];

        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        if (is_resource($this->outputStream)) {
            fwrite($this->outputStream, $json);
        } else {
            file_put_contents('php://stdout', $json);
        }
    }

    private function sanitizeContext(array $context): array
    {
        $sanitized = [];
        foreach ($context as $key => $value) {
            $lowerKey = strtolower((string)$key);
            if (in_array($lowerKey, self::SENSITIVE_KEYS, true)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif ($lowerKey === 'phone' && is_string($value) && strlen($value) >= 7) {
                $sanitized[$key] = substr($value, 0, 3) . '****' . substr($value, -4);
            } elseif ($lowerKey === 'email' && is_string($value) && str_contains($value, '@')) {
                $parts = explode('@', $value, 2);
                $sanitized[$key] = substr($parts[0], 0, 2) . '***@' . $parts[1];
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeContext($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }
}
