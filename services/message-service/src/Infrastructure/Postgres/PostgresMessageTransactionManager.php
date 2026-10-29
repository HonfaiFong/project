<?php

declare(strict_types=1);

namespace Services\MessageService\Infrastructure\Postgres;

use Contracts\Errors\ErrorCode;
use LogicException;
use PDO;
use Packages\Database\PostgresErrorClassifier;
use Packages\GrpcCommon\OperationBudget;
use RuntimeException;
use Services\MessageService\Domain\Repository\MessageTransactionManagerInterface;
use Throwable;

final readonly class PostgresMessageTransactionManager implements MessageTransactionManagerInterface
{
    public function __construct(private PDO|PostgresMessageConnection $connection) {}

    public function transaction(callable $operation, ?OperationBudget $budget = null): mixed
    {
        $budget ??= new OperationBudget(600);
        $budget->remainingMilliseconds(25);
        $pdo = $this->connection instanceof PostgresMessageConnection ? $this->connection->pdo() : $this->connection;
        if ($pdo->inTransaction()) {
            throw new LogicException('Message transactions cannot be nested');
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $budget->remainingMilliseconds(25);
            try {
                $pdo->beginTransaction();
                $result = $operation(new PostgresMessageWriteTransaction($pdo, $budget));
                (new PostgresMessageQuery($pdo, $budget))->execute('SELECT 1');
            } catch (Throwable $exception) {
                $this->rollback($pdo);
                if ($this->connection instanceof PostgresMessageConnection) {
                    $this->connection->discardIfBroken($exception);
                }
                if (PostgresErrorClassifier::isRetryableTransactionFailure($exception)
                    && $attempt < 2 && $budget->hasTime(50)) {
                    continue;
                }
                throw $exception;
            }
            // The write callback is never replayed after COMMIT has been sent.
            try {
                $pdo->commit();
                return $result;
            } catch (Throwable $exception) {
                $this->rollback($pdo);
                if ($this->connection instanceof PostgresMessageConnection) {
                    $this->connection->discard();
                }
                throw new RuntimeException('Message commit was not confirmed; retry with the same client_message_id', ErrorCode::RPC_TIMEOUT->value, $exception);
            }
        }
        throw new LogicException('Unreachable transaction retry state');
    }

    private function rollback(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable) {}
        }
    }
}
