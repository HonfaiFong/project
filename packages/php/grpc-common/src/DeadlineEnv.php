<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

use InvalidArgumentException;

/**
 * Strict environment parsing for gRPC deadline knobs.
 *
 * Deadlines become gRPC timeouts in microseconds, so a zero, negative, or
 * non-numeric value must fail loudly at startup: "(int)" casting silently
 * clamps garbage to values like 1 ms (every call times out) and the falsy
 * "?:" idiom hides a missing override behind the default. The gateway config
 * and the auth-service bootstrap both read these knobs, so the parsing lives
 * here as the single contract.
 */
final class DeadlineEnv
{
    /**
     * Resolve a positive-millisecond deadline. Unset or blank resolves to the
     * shipped default; every other value must be a positive integer literal.
     */
    public static function positiveMilliseconds(string $name, int $default): int
    {
        $raw = getenv($name);
        $raw = $raw === false ? '' : trim($raw);
        if ($raw === '') {
            return $default;
        }
        if (!ctype_digit($raw) || (int)$raw < 1) {
            throw new InvalidArgumentException(
                $name . ' must resolve to a positive deadline in milliseconds, got \'' . $raw . '\''
            );
        }

        return (int)$raw;
    }
}
