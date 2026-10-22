<?php

declare(strict_types=1);

namespace Services\MessageService\Infrastructure\Mongo;

use MongoDB\Client;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\Session;
use MongoDB\Driver\WriteConcern;
use Packages\GrpcCommon\OperationBudget;
use Services\MessageService\Domain\Repository\MessageWriteTransactionInterface;

final class NativeMongoTransactionSession implements MongoTransactionSession
{
    private readonly Session $session;

    public function __construct(private readonly Client $client, private readonly string $database)
    {
        $this->session = $client->startSession();
    }

    public function begin(OperationBudget $budget, int $writeTimeoutMs, int $commitTimeoutMs): MessageWriteTransactionInterface
    {
        $this->session->startTransaction([
            'readPreference' => new ReadPreference(ReadPreference::PRIMARY),
            'readConcern' => new ReadConcern(ReadConcern::SNAPSHOT),
            'writeConcern' => new WriteConcern(WriteConcern::MAJORITY, $writeTimeoutMs, true),
            'maxCommitTimeMS' => $commitTimeoutMs,
        ]);

        return new MongoMessageWriteTransaction(
            $this->client,
            $this->database,
            $this->session,
            budget: $budget,
            commitTimeoutMs: $commitTimeoutMs,
        );
    }

    public function commit(): void
    {
        $this->session->commitTransaction();
    }

    public function abort(): void
    {
        $this->session->abortTransaction();
    }

    public function end(): void
    {
        $this->session->endSession();
    }
}
