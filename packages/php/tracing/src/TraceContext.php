<?php

declare(strict_types=1);

namespace Packages\Tracing;

use Random\RandomException;

/**
 * 保存关联标识和可信用户身份的请求追踪上下文。
 *
 * 架构并发说明与技术债务：
 * 当前用 `private static ?self $current = null` 保存活动上下文。在现有同步、每工作进程独立处理的模型（Workerman
 * 单事件循环工作进程或 RoadRunner 工作进程）下是安全的：每个请求在处理下一请求前，都会在 `finally` 块中通过
 * `TraceContext::reset()` 清理上下文。
 *
 * TODO（启用 Webman 协程、Fiber 或异步并发前必须处理）：
 * 启用协程或 Fiber 并发后，同一工作进程的并发请求共享 `static $current`，协程让出执行权时会导致追踪上下文串扰。
 * 启用协程前必须将此静态属性迁移到协程或 Fiber 局部存储，如 Swow/Swoole Coroutine::getContext() 或 Fiber 作用域
 * 上下文。
 */
final class TraceContext
{
    private static ?self $current = null;

    public function __construct(
        public readonly string $requestId,
        public readonly string $traceId,
        public readonly ?string $userId = null,
        public readonly ?string $personaId = null,
        public readonly ?string $deviceId = null,
        public readonly ?string $appVersion = null,
        public readonly ?string $traceparent = null,
    ) {
    }

    /** @throws RandomException */
    public static function create(
        ?string $requestId = null,
        ?string $traceId = null,
        ?string $userId = null,
        ?string $personaId = null,
        ?string $deviceId = null,
        ?string $appVersion = null,
        ?string $traceparent = null,
    ): self {
        $reqId = $requestId ?: self::generateUuidV7();
        $trId = $traceId ?: bin2hex(random_bytes(16));
        $tp = $traceparent ?: sprintf('00-%s-%s-01', $trId, bin2hex(random_bytes(8)));

        return new self(
            requestId: $reqId,
            traceId: $trId,
            userId: $userId,
            personaId: $personaId,
            deviceId: $deviceId,
            appVersion: $appVersion,
            traceparent: $tp,
        );
    }

    public static function setCurrent(?self $context): void
    {
        self::$current = $context;
    }

    /** @throws RandomException */
    public static function current(): self
    {
        if (self::$current === null) {
            self::$current = self::create();
        }
        return self::$current;
    }

    public static function reset(): void
    {
        self::$current = null;
    }

    /**
     * @return array<string, string>
     */
    public function toHeaders(): array
    {
        $headers = [
            'x-request-id' => $this->requestId,
            'x-trace-id'   => $this->traceId,
            'traceparent'  => $this->traceparent ?? '',
        ];

        if ($this->userId !== null) {
            $headers['x-user-id'] = $this->userId;
        }
        if ($this->personaId !== null) {
            $headers['x-persona-id'] = $this->personaId;
        }
        if ($this->deviceId !== null) {
            $headers['x-device-id'] = $this->deviceId;
        }
        if ($this->appVersion !== null) {
            $headers['x-app-version'] = $this->appVersion;
        }

        return $headers;
    }

    /** @throws RandomException */
    public static function generateUuidV7(): string
    {
        // 48 位毫秒时间戳
        $milliTime = (int)floor(microtime(true) * 1000);
        $timeHex = str_pad(dechex($milliTime), 12, '0', STR_PAD_LEFT);

        // 12 位 rand_a，包含版本 7（0111）
        $randA = random_int(0, 0x0fff);
        $randAHex = '7' . str_pad(dechex($randA), 3, '0', STR_PAD_LEFT);

        // 62 位 rand_b，包含变体 10
        $randB = random_int(0, 0x3fff);
        $randBHex = dechex(0x8000 | $randB);
        $randTail = bin2hex(random_bytes(6));

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($timeHex, 0, 8),
            substr($timeHex, 8, 4),
            $randAHex,
            $randBHex,
            $randTail
        );
    }
}
