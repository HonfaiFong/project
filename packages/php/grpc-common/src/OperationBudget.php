<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

use Contracts\Errors\ErrorCode;
use RuntimeException;

/** A monotonic deadline shared by RPC authorization and the persistence operation. */
final class OperationBudget
{
    private readonly int $deadlineNs;

    public function __construct(int $budgetMilliseconds)
    {
        if ($budgetMilliseconds < 1) {
            throw new RuntimeException('Operation budget must be positive', ErrorCode::RPC_TIMEOUT->value);
        }
        $this->deadlineNs = hrtime(true) + ($budgetMilliseconds * 1_000_000);
    }

    public static function fromEnvironment(string $name, int $defaultMilliseconds): self
    {
        $value = getenv($name);
        $milliseconds = $value === false ? $defaultMilliseconds : filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($milliseconds) || $milliseconds < 1 || $milliseconds > 120_000) {
            throw new RuntimeException($name . ' must be between 1 and 120000 milliseconds');
        }
        return new self($milliseconds);
    }

    public function remainingMilliseconds(int $reserveMilliseconds = 0): int
    {
        $remaining = (int)floor(($this->deadlineNs - hrtime(true)) / 1_000_000) - $reserveMilliseconds;
        if ($remaining < 1) {
            throw new RuntimeException('RPC operation budget exhausted', ErrorCode::RPC_TIMEOUT->value);
        }
        return $remaining;
    }

    public function hasTime(int $reserveMilliseconds = 0): bool
    {
        return $this->deadlineNs - hrtime(true) > $reserveMilliseconds * 1_000_000;
    }
}
