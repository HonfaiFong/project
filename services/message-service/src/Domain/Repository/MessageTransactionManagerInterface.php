<?php

declare(strict_types=1);

namespace Services\MessageService\Domain\Repository;

use Packages\GrpcCommon\OperationBudget;

interface MessageTransactionManagerInterface
{
    /**
     * @template T
     * @param callable(MessageWriteTransactionInterface): T $operation
     * @param ?OperationBudget $budget
     * @return T
     */
    public function transaction(callable $operation, ?OperationBudget $budget = null): mixed;
}
