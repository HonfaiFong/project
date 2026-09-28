<?php

declare(strict_types=1);

namespace Tests;

use RuntimeException;

class TestCase
{
    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message ?: sprintf('Expected %s, got %s', var_export($expected, true), var_export($actual, true)));
        }
    }

    public function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected != $actual) {
            throw new RuntimeException($message ?: sprintf('Expected %s, got %s', var_export($expected, true), var_export($actual, true)));
        }
    }

    public function assertNotEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected == $actual) {
            throw new RuntimeException($message ?: sprintf('Expected not equals %s', var_export($actual, true)));
        }
    }

    public function assertTrue(mixed $condition, string $message = ''): void
    {
        if ($condition !== true) {
            throw new RuntimeException($message ?: 'Expected condition to be true');
        }
    }

    public function assertFalse(mixed $condition, string $message = ''): void
    {
        if ($condition !== false) {
            throw new RuntimeException($message ?: 'Expected condition to be false');
        }
    }

    public function assertNotEmpty(mixed $actual, string $message = ''): void
    {
        if (empty($actual)) {
            throw new RuntimeException($message ?: 'Expected value not to be empty');
        }
    }

    public function assertGreaterThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($actual <= $expected) {
            throw new RuntimeException($message ?: sprintf('Expected %s > %s', var_export($actual, true), var_export($expected, true)));
        }
    }

    public function assertGreaterThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($actual < $expected) {
            throw new RuntimeException($message ?: sprintf('Expected %s >= %s', var_export($actual, true), var_export($expected, true)));
        }
    }

    public function assertLessThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($actual >= $expected) {
            throw new RuntimeException($message ?: sprintf('Expected %s < %s', var_export($actual, true), var_export($expected, true)));
        }
    }

    public function assertLessThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($actual > $expected) {
            throw new RuntimeException($message ?: sprintf('Expected %s <= %s', var_export($actual, true), var_export($expected, true)));
        }
    }

    public function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        if (!in_array($needle, $haystack, true)) {
            throw new RuntimeException($message ?: sprintf('Expected %s to be in array', var_export($needle, true)));
        }
    }

    public function assertStringStartsWith(string $prefix, string $string, string $message = ''): void
    {
        if (!str_starts_with($string, $prefix)) {
            throw new RuntimeException($message ?: sprintf('Expected %s to start with %s', $string, $prefix));
        }
    }

    public function assertJson(string $actual, string $message = ''): void
    {
        json_decode($actual);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException($message ?: 'String is not valid JSON: ' . json_last_error_msg());
        }
    }
}
