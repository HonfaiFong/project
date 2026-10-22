<?php

declare(strict_types=1);

namespace Services\MessageService\Infrastructure\Mongo;

use Packages\GrpcCommon\OperationBudget;
use Services\MessageService\Domain\Repository\MessageWriteTransactionInterface;

interface MongoTransactionSession
{
    public function begin(OperationBudget $budget, int $writeTimeoutMs, int $commitTimeoutMs): MessageWriteTransactionInterface;

    public function commit(): void;

    public function abort(): void;

    public function end(): void;
}
