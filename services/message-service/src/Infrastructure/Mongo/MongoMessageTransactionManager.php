<?php

declare(strict_types=1);

namespace Services\MessageService\Infrastructure\Mongo;

use Contracts\Errors\ErrorCode;
use MongoDB\Client;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\WriteConcern;
use Packages\Database\MongoConnectionFactory;
use Packages\GrpcCommon\OperationBudget;
use Services\MessageService\Domain\Repository\MessageTransactionManagerInterface;
use Services\MessageService\Domain\Repository\MessageWriteTransactionInterface;
use RuntimeException;
use Throwable;

final class MongoMessageTransactionManager implements MessageTransactionManagerInterface
{
    public function __construct(private readonly Client $client, private readonly string $database = 'message_db')
    {
    }

    public function transaction(callable $operation, ?OperationBudget $budget = null): mixed
    {
        $budget ??= new OperationBudget(600);
        $maxAttempts = MongoConnectionFactory::rpcMaxTransactionAttempts();
        $retryDelayMs = MongoConnectionFactory::rpcRetryDelayMs();
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $session = $this->client->startSession();
            $active = false;
            $commitUnknown = false;
            try {
                $commitTimeoutMs = MongoConnectionFactory::rpcCommitTimeoutMs($budget);
                $writeTimeoutMs = MongoConnectionFactory::rpcWriteTimeoutMs($budget, $commitTimeoutMs);
                $budget->remainingMilliseconds(MongoConnectionFactory::rpcReserveMs() + $commitTimeoutMs);
                $session->startTransaction([
                    'readPreference' => new ReadPreference(ReadPreference::PRIMARY),
                    'readConcern' => new ReadConcern(ReadConcern::SNAPSHOT),
                    'writeConcern' => new WriteConcern(WriteConcern::MAJORITY, $writeTimeoutMs, true),
                    'maxCommitTimeMS' => $commitTimeoutMs,
                ]);
                $active = true;
                $transaction = new MongoMessageWriteTransaction(
                    $this->client,
                    $this->database,
                    $session,
                    budget: $budget,
                    commitTimeoutMs: $commitTimeoutMs,
                );
                $result = $operation($transaction);
                while (true) {
                    try {
                        $budget->remainingMilliseconds(MongoConnectionFactory::rpcReserveMs() + $commitTimeoutMs);
                        $session->commitTransaction();
                        $active = false;
                        return $result;
                    } catch (Throwable $exception) {
                        if ($this->hasErrorLabel($exception, 'UnknownTransactionCommitResult')) {
                            $commitUnknown = true;
                            if ($budget->hasTime(MongoConnectionFactory::rpcReserveMs() + $commitTimeoutMs + $retryDelayMs)) {
                                if ($retryDelayMs > 0) {
                                    usleep($retryDelayMs * 1000);
                                }
                                continue;
                            }
                        }
                        throw $exception;
                    }
                }
            } catch (Throwable $exception) {
                if ($active && !$commitUnknown) {
                    try {
                        $session->abortTransaction();
                    } catch (Throwable) {
                        // Preserve the original database error and discard this session.
                    }
                }
                if (!$commitUnknown && $attempt + 1 < $maxAttempts && $this->hasErrorLabel($exception, 'TransientTransactionError')
                    && $budget->hasTime(MongoConnectionFactory::rpcReserveMs() + $commitTimeoutMs + $retryDelayMs * ($attempt + 1))) {
                    if ($retryDelayMs > 0) {
                        usleep($retryDelayMs * ($attempt + 1) * 1000);
                    }
                    continue;
                }
                if ($commitUnknown) {
                    throw new RuntimeException('MongoDB commit result is unknown after the request deadline', ErrorCode::RPC_TIMEOUT->value, $exception);
                }
                throw $exception;
            } finally {
                $session->endSession();
            }
        }
        throw new RuntimeException('MongoDB transaction retry budget exhausted', ErrorCode::SERVICE_UNAVAILABLE->value);
    }

    private function hasErrorLabel(Throwable $exception, string $label): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (method_exists($current, 'hasErrorLabel') && $current->hasErrorLabel($label)) {
                return true;
            }
        }
        return false;
    }
}
