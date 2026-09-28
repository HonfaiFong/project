<?php

declare(strict_types=1);

namespace Packages\Tracing;

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

    public static function generateUuidV7(): string
    {
        // 48-bit timestamp in milliseconds
        $milliTime = (int)floor(microtime(true) * 1000);
        $timeHex = str_pad(dechex($milliTime), 12, '0', STR_PAD_LEFT);

        // 12-bit rand_a with version 7 (0111)
        $randA = random_int(0, 0x0fff);
        $randAHex = '7' . str_pad(dechex($randA), 3, '0', STR_PAD_LEFT);

        // 62-bit rand_b with variant 10
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
