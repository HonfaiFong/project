<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

/**
 * 记录每次日志调用的级别与上下文，供断言“失败原因被记录但不对外回显”一类契约使用。
 */
final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => (string)$level,
            'message' => (string)$message,
            'context' => $context,
        ];
    }

    /** @param array<string, mixed> $context */
    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
}
