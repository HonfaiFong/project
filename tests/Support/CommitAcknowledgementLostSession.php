<?php

declare(strict_types=1);

namespace Tests\Support;

use Packages\GrpcCommon\OperationBudget;
use Services\MessageService\Domain\Repository\MessageWriteTransactionInterface;
use Services\MessageService\Infrastructure\Mongo\MongoTransactionSession;

/** Commits to the real database, then hides every commit acknowledgement. */
final class CommitAcknowledgementLostSession implements MongoTransactionSession
{
    private bool $committed = false;

    public function __construct(private readonly MongoTransactionSession $session)
    {
    }

    public function begin(OperationBudget $budget, int $writeTimeoutMs, int $commitTimeoutMs): MessageWriteTransactionInterface
    {
        return $this->session->begin($budget, $writeTimeoutMs, $commitTimeoutMs);
    }

    public function commit(): void
    {
        if (!$this->committed) {
            $this->session->commit();
            $this->committed = true;
        }
        throw new LabeledMongoException('UnknownTransactionCommitResult');
    }

    public function abort(): void
    {
        $this->session->abort();
    }

    public function end(): void
    {
        $this->session->end();
    }
}
