<?php

declare(strict_types=1);

namespace Packages\Logging;

use Packages\Tracing\TraceContext;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Random\RandomException;
use Stringable;

final class JsonLogger extends AbstractLogger
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

    /**
     * @throws RandomException
     * @throws InvalidArgumentException
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!in_array($level, [
            LogLevel::EMERGENCY,
            LogLevel::ALERT,
            LogLevel::CRITICAL,
            LogLevel::ERROR,
            LogLevel::WARNING,
            LogLevel::NOTICE,
            LogLevel::INFO,
            LogLevel::DEBUG,
        ], true)) {
            throw new InvalidArgumentException('Unsupported log level');
        }

        $trace = TraceContext::current();

        $entry = [
            'timestamp'  => gmdate('Y-m-d\TH:i:s.v\Z'),
            'level'      => strtoupper((string)$level),
            'service'    => $this->serviceName,
            'instance'   => $this->instanceId,
            'trace_id'   => $trace->traceId,
            'request_id' => $trace->requestId,
            'user_id'    => $trace->userId ?? '',
            'message'    => (string)$message,
            'context'    => $this->sanitizeContext($context),
        ];

        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        if (is_resource($this->outputStream)) {
            fwrite($this->outputStream, $json);
        } else {
            // 默认走 STDERR:RoadRunner worker 的 STDOUT 被 goridge 协议独占,
            // 直接写会破坏帧校验导致 worker 被 rr 杀掉
            file_put_contents('php://stderr', $json);
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
