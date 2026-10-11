<?php

declare(strict_types=1);

namespace Packages\Observability;

use Throwable;

final class Span
{
    public const KIND_INTERNAL = 1;
    public const KIND_SERVER = 2;
    public const KIND_CLIENT = 3;

    private bool $ended = false;
    private int $endMonotonicNs = 0;
    private int $endTimeUnixNano = 0;
    private int $statusCode = 0;
    private ?string $statusMessage = null;

    /** @var array<string, scalar|null> */
    private array $attributes;

    /** @param array<string, scalar|null> $attributes */
    public function __construct(
        private readonly string $name,
        private readonly string $traceId,
        private readonly string $spanId,
        private readonly ?string $parentSpanId,
        private readonly int $kind,
        private readonly int $startMonotonicNs,
        private readonly int $startTimeUnixNano,
        array $attributes,
        private readonly SpanExporter $exporter,
    ) {
        $this->attributes = $attributes;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    public function spanId(): string
    {
        return $this->spanId;
    }

    public function parentSpanId(): ?string
    {
        return $this->parentSpanId;
    }

    public function kind(): int
    {
        return $this->kind;
    }

    public function startTimeNs(): int
    {
        return $this->startMonotonicNs;
    }

    public function endTimeNs(): int
    {
        return $this->endMonotonicNs > 0 ? $this->endMonotonicNs : max($this->startMonotonicNs, hrtime(true));
    }

    public function startTimeUnixNano(): int
    {
        return $this->startTimeUnixNano;
    }

    public function endTimeUnixNano(): int
    {
        return $this->endTimeUnixNano > 0
            ? $this->endTimeUnixNano
            : max($this->startTimeUnixNano, self::unixTimeNano());
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function statusMessage(): ?string
    {
        return $this->statusMessage;
    }

    /** @return array<string, scalar|null> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function traceparent(): string
    {
        return sprintf('00-%s-%s-01', $this->traceId, $this->spanId);
    }

    public function setAttribute(string $key, mixed $value): self
    {
        if ($value !== null) {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    public function setStatus(int $statusCode, ?string $message = null): self
    {
        $this->statusCode = $statusCode;
        $this->statusMessage = $message;

        return $this;
    }

    public function recordException(Throwable $exception): self
    {
        $grpcStatus = null;
        if (property_exists($exception, 'grpcStatus')) {
            $candidate = $exception->grpcStatus;
            $grpcStatus = is_int($candidate) || is_string($candidate) ? (int)$candidate : null;
        }
        $status = $grpcStatus ?? (is_int($exception->getCode()) ? $exception->getCode() : 2);
        $this->setStatus(2, $exception->getMessage());
        $this->setAttribute('error.type', $exception::class);
        $this->setAttribute('rpc.grpc.status_code', $grpcStatus ?? $status);

        return $this;
    }

    public function end(): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->endMonotonicNs = max($this->startMonotonicNs, hrtime(true));
        $this->endTimeUnixNano = max($this->startTimeUnixNano, self::unixTimeNano());
        try {
            $this->exporter->export($this);
        } catch (Throwable) {
            // Telemetry is fail-open: an unavailable collector must not fail a business request.
        }
    }

    private static function unixTimeNano(): int
    {
        return (int)round(microtime(true) * 1_000_000_000);
    }
}
