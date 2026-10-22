<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/** Application-owned fault injection; never overrides a final driver method. */
final class LabeledMongoException extends RuntimeException
{
    public function __construct(private readonly string $label)
    {
        parent::__construct('Simulated MongoDB error: ' . $label);
    }

    public function hasErrorLabel(string $label): bool
    {
        return $this->label === $label;
    }
}
