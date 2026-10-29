<?php

declare(strict_types=1);

namespace Services\MessageService\Infrastructure\Postgres;

use PDO;
use PDOStatement;
use PDOException;
use Contracts\Errors\ErrorCode;
use Packages\Database\PostgresErrorClassifier;
use Packages\GrpcCommon\OperationBudget;
use RuntimeException;

/** Bounds SQL work by the remaining RPC budget, including waits for row locks. */
final readonly class PostgresMessageQuery
{
    public function __construct(private PDO|PostgresMessageConnection $connection, private ?OperationBudget $budget = null) {}

    /** @param array<string, mixed> $parameters */
    public function execute(string $sql, array $parameters = []): PDOStatement
    {
        $this->budget?->remainingMilliseconds(25);
        $pdo = $this->connection instanceof PostgresMessageConnection ? $this->connection->pdo() : $this->connection;
        $remaining = $this->budget?->remainingMilliseconds(25) ?? 5000;
        try {
            $timeout = $pdo->prepare("SELECT set_config('statement_timeout', :timeout, :local), set_config('lock_timeout', :lock_timeout, :local_lock)");
            $timeout->execute([
                'timeout' => $remaining . 'ms', 'lock_timeout' => $remaining . 'ms',
                'local' => $pdo->inTransaction() ? 'true' : 'false',
                'local_lock' => $pdo->inTransaction() ? 'true' : 'false',
            ]);
            $statement = $pdo->prepare($sql);
            $statement->execute($parameters);
        } catch (PDOException $exception) {
            if ($this->connection instanceof PostgresMessageConnection) {
                $this->connection->discardIfBroken($exception);
            }
            if (in_array(PostgresErrorClassifier::classify($exception), ['query_canceled', 'lock_not_available'], true)) {
                throw new RuntimeException('Message database operation timed out', ErrorCode::RPC_TIMEOUT->value, $exception);
            }
            throw $exception;
        }
        return $statement;
    }
}
