<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

/**
 * Per-call remaining-deadline holder, mirroring TraceContext's worker-local
 * pattern. RoadRunner's gRPC transport does not surface the incoming call's
 * deadline to PHP workers, so the gateway propagates its absolute deadline as
 * the x-deadline-unix-ms metadata key; the receiving service pins it here
 * after caller authorization and downstream hops clamp their own budgets to
 * whatever is left, so the outer deadline always rules.
 */
final class DeadlineContext
{
    private static ?int $deadlineEpochMs = null;

    public static function setCurrent(?int $deadlineEpochMs): void
    {
        self::$deadlineEpochMs = $deadlineEpochMs;
    }

    public static function current(): ?int
    {
        return self::$deadlineEpochMs;
    }

    /**
     * Milliseconds left before the current call's deadline; null when the
     * caller propagated none and configured budgets apply unchanged.
     */
    public static function remainingMs(): ?int
    {
        if (self::$deadlineEpochMs === null) {
            return null;
        }

        return max(0, self::$deadlineEpochMs - self::nowEpochMs());
    }

    /**
     * Clamp a configured hop budget to the remaining deadline. Null means the
     * outer deadline has already expired and the hop must fail fast instead of
     * spending a fresh full budget the caller never granted.
     */
    public static function clampHopBudgetMs(int $configuredMs): ?int
    {
        $remainingMs = self::remainingMs();
        if ($remainingMs === null) {
            return $configuredMs;
        }
        if ($remainingMs <= 0) {
            return null;
        }

        return max(1, min($configuredMs, $remainingMs));
    }

    public static function reset(): void
    {
        self::$deadlineEpochMs = null;
    }

    public static function nowEpochMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }
}
