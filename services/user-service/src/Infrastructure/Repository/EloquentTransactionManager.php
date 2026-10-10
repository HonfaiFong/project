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

    /** @throws Throwable 将事务失败传播给调用方。 */
    public function transaction(callable $callback): mixed
    {
        $connection = EloquentConnection::fromPdo($this->pdo);

        // 外层调用方（如 Outbox 中继）已持有 PDO 事务时，复用该事务，避免嵌套开启第二个 BEGIN。
        if ($this->pdo->inTransaction() && $connection->transactionLevel() === 0) {
            return $callback();
        }

        return $connection->transaction($callback);
    }
}
