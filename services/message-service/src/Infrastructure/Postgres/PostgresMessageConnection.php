<?php

declare(strict_types=1);

namespace Services\MessageService\Infrastructure\Postgres;

use Closure;
use PDO;
use Packages\Database\PostgresErrorClassifier;
use Throwable;

/** Reconnects on a subsequent request without ever replaying a database write. */
final class PostgresMessageConnection
{
    public function __construct(private ?PDO $pdo, private readonly Closure $factory) {}

    public function pdo(): PDO
    {
        return $this->pdo ??= ($this->factory)();
    }

    public function discard(): void
    {
        $this->pdo = null;
    }

    public function discardIfBroken(Throwable $exception): void
    {
        if (PostgresErrorClassifier::classify($exception) === 'connection_failure'
            || PostgresErrorClassifier::sqlState($exception) === 'HY000') {
            $this->discard();
        }
    }
}
