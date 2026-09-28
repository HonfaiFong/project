<?php

declare(strict_types=1);

namespace Services\UserService\Infrastructure\Repository;

use PDO;
use Services\UserService\Domain\Repository\TransactionManagerInterface;
use Throwable;

final class PdoTransactionManager implements TransactionManagerInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function transaction(callable $callback): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $e) {
            // @phpstan-ignore booleanAnd.rightAlwaysFalse (the callback runs arbitrary SQL and may have committed/rolled back the shared PDO)
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}
