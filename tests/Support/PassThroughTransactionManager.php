<?php

declare(strict_types=1);

namespace Tests\Support;

use Services\UserService\Domain\Repository\TransactionManagerInterface;

final class PassThroughTransactionManager implements TransactionManagerInterface
{
    public function transaction(callable $callback): mixed
    {
        return $callback();
    }
}
