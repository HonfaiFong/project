<?php

declare(strict_types=1);

namespace Services\UserService\Infrastructure\Repository;

use Services\UserService\Domain\Repository\TransactionManagerInterface;

final class PassThroughTransactionManager implements TransactionManagerInterface
{
    public function transaction(callable $callback): mixed
    {
        return $callback();
    }
}
