<?php

declare(strict_types=1);

namespace Packages\Observability;

/** Exporter contract kept deliberately small so the runtime has no SDK dependency. */
interface SpanExporter
{
    public function export(Span $span): void;
}
