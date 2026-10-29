<?php

declare(strict_types=1);

namespace Services\MessageService\Infrastructure\Postgres;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Packages\Database\PostgresErrorClassifier;
use Packages\GrpcCommon\OperationBudget;
use Services\MessageService\Domain\Repository\MessageRepositoryInterface;

final readonly class PostgresMessageRepository implements MessageRepositoryInterface
{
    public function __construct(private PDO|PostgresMessageConnection $pdo) {}

    public function findBySenderClientId(string $senderUserId, string $clientMessageId, ?OperationBudget $budget = null): ?array
    {
        $row = (new PostgresMessageQuery($this->pdo, $budget))->execute(
            'SELECT * FROM messages WHERE sender_user_id = :sender AND client_message_id = :client',
            ['sender' => $senderUserId, 'client' => $clientMessageId],
        )->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->toDomain($row);
    }

    public function idempotencyMatches(array $existing, array $request, ?OperationBudget $budget = null): bool
    {
        if ((string)$existing['conversation_id'] !== (string)$request['conversation_id']
            || (int)$existing['type'] !== (int)$request['type']) {
            return false;
        }
        // JSONB equality preserves object/array distinctions and numeric precision.
        // Decoding through PHP floats would accept different large JSON numbers.
        try {
            $equal = (new PostgresMessageQuery($this->pdo, $budget))->execute(
                'SELECT CAST(:existing AS jsonb) = CAST(:requested AS jsonb)',
                ['existing' => $existing['content'], 'requested' => $request['content']],
            )->fetchColumn();
        } catch (PDOException $exception) {
            if (in_array(PostgresErrorClassifier::sqlState($exception), ['22P02', '22003', '22021', '22P05'], true)) {
                return false;
            }
            throw $exception;
        }
        return in_array($equal, [true, 't', '1', 1], true);
    }

    public function listAfter(string $conversationId, string $afterSeq, int $limit, ?OperationBudget $budget = null): array
    {
        return $this->rows(
            'SELECT * FROM messages WHERE conversation_id = :conversation AND seq > :seq ORDER BY seq ASC LIMIT ' . (max(1, min(100, $limit)) + 1),
            ['conversation' => $conversationId, 'seq' => $afterSeq], $budget,
        );
    }

    public function listPage(string $conversationId, int $limit, ?string $cursor, ?OperationBudget $budget = null): array
    {
        $parameters = ['conversation' => $conversationId];
        $bound = '';
        if ($cursor !== null && $cursor !== '') {
            $bound = ' AND seq < :seq';
            $parameters['seq'] = $cursor;
        }
        return $this->rows(
            'SELECT * FROM messages WHERE conversation_id = :conversation' . $bound
                . ' ORDER BY seq DESC LIMIT ' . (max(1, min(100, $limit)) + 1),
            $parameters, $budget,
        );
    }

    public function latestSequence(string $conversationId, ?OperationBudget $budget = null): string
    {
        $seq = (new PostgresMessageQuery($this->pdo, $budget))->execute(
            'SELECT seq FROM messages WHERE conversation_id = :conversation ORDER BY seq DESC LIMIT 1',
            ['conversation' => $conversationId],
        )->fetchColumn();
        return $seq === false ? '0' : (string)$seq;
    }

    /** @param array<string,mixed> $parameters @return list<array<string,mixed>> */
    private function rows(string $sql, array $parameters, ?OperationBudget $budget): array
    {
        $rows = (new PostgresMessageQuery($this->pdo, $budget))->execute($sql, $parameters)->fetchAll(PDO::FETCH_ASSOC);
        return array_map($this->toDomain(...), $rows);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function toDomain(array $row): array
    {
        foreach (['message_id', 'conversation_id', 'seq', 'sender_user_id', 'sender_persona_id'] as $field) {
            $row[$field] = (string)$row[$field];
        }
        $row['type'] = (int)$row['type'];
        $row['sender_identity_mode'] = (int)$row['sender_identity_mode'];
        $row['created_at'] = (new DateTimeImmutable((string)$row['created_at']))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        return $row;
    }
}
