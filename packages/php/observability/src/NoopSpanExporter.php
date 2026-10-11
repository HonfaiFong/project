<?php

declare(strict_types=1);

namespace Packages\Observability;

final class NoopSpanExporter implements SpanExporter
{
    public function export(Span $span): void
    {
    }
}
