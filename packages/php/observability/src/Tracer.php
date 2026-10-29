<?php

declare(strict_types=1);

namespace Packages\Observability;

use Packages\Tracing\TraceContext;

final class Tracer
{
    private static ?SpanExporter $exporter = null;

    /**
     * @throws \Random\RandomException
     */
    public static function enter(
        string $name,
        int $kind = Span::KIND_INTERNAL,
        /** @var array<string, scalar|null> */ array $attributes = [],
    ): SpanScope {
        $previous = TraceContext::peek();
        $parent = TraceContext::current();
        $startMonotonicNs = hrtime(true);
        $startTimeUnixNano = (int)round(microtime(true) * 1_000_000_000);
        $span = new Span(
            name: $name,
            traceId: self::traceId($parent->traceId),
            spanId: bin2hex(random_bytes(8)),
            parentSpanId: self::spanId($parent->traceparent),
            kind: $kind,
            startMonotonicNs: $startMonotonicNs,
            startTimeUnixNano: $startTimeUnixNano,
            attributes: $attributes,
            exporter: self::exporter(),
        );
        TraceContext::setCurrent($parent->withTraceparent($span->traceparent()));

        return new SpanScope($span, $previous);
    }

    public static function setExporter(?SpanExporter $exporter): void
    {
        self::$exporter = $exporter;
    }

    public static function resetExporter(): void
    {
        self::$exporter = null;
    }

    private static function exporter(): SpanExporter
    {
        return self::$exporter ??= new OtlpHttpExporter();
    }

    /**
     * @throws \Random\RandomException
     */
    private static function traceId(string $candidate): string
    {
        return preg_match('/\A[0-9a-f]{32}\z/i', $candidate) === 1 ? strtolower($candidate) : bin2hex(random_bytes(16));
    }

    private static function spanId(?string $traceparent): ?string
    {
        if ($traceparent !== null && preg_match('/\A00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}\z/i', trim($traceparent), $matches) === 1) {
            return strtolower($matches[2]);
        }

        return null;
    }
}
