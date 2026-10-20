<?php

declare(strict_types=1);

namespace Packages\Database;

use PDOException;
use Throwable;

final class PostgresErrorClassifier
{
    public static function sqlState(Throwable $exception): ?string
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException && isset($current->errorInfo[0])) {
                return (string)$current->errorInfo[0];
            }
            $code = (string)$current->getCode();
            if (preg_match('/\A[0-9A-Z]{5}\z/', $code) === 1) {
                return $code;
            }
        }
        return null;
    }

    public static function classify(Throwable $exception): string
    {
        return match (self::sqlState($exception)) {
            '23505', '23000' => 'unique_violation',
            '23503' => 'foreign_key_violation',
            '23514' => 'check_violation',
            '23502' => 'not_null_violation',
            '40001' => 'serialization_failure',
            '40P01' => 'deadlock',
            '55P03' => 'lock_not_available',
            '57014' => 'query_canceled',
            '25P02' => 'transaction_aborted',
            default => str_starts_with(self::sqlState($exception) ?? '', '08') ? 'connection_failure' : 'unknown',
        };
    }

    public static function isUniqueViolation(Throwable $exception): bool
    {
        return in_array(self::sqlState($exception), ['23505', '23000'], true);
    }

    public static function isRetryableTransactionFailure(Throwable $exception): bool
    {
        return in_array(self::sqlState($exception), ['40001', '40P01'], true);
    }
}
