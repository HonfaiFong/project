<?php

declare(strict_types=1);

namespace Services\UserService\Infrastructure\Repository;

use Packages\Database\EloquentConnection;
use PDO;
use Throwable;
use Services\UserService\Domain\Repository\TransactionManagerInterface;

final readonly class EloquentTransactionManager implements TransactionManagerInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @throws Throwable Propagates a failed transaction to the caller. */
    public function transaction(callable $callback): mixed
    {
        $connection = EloquentConnection::fromPdo($this->pdo);

        // An outer caller already owns a PDO-level transaction (for example the
        // outbox relay): reuse it instead of nesting a second BEGIN.
        if ($this->pdo->inTransaction() && $connection->transactionLevel() === 0) {
            return $callback();
        }

        return $connection->transaction($callback);
    }
}
