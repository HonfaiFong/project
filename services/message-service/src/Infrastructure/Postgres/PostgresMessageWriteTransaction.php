<?php

declare(strict_types=1);

namespace Services\MessageService\Infrastructure\Postgres;

use PDO;
use PDOException;
use Packages\Database\PostgresErrorClassifier;
use Packages\GrpcCommon\OperationBudget;
use Services\MessageService\Domain\Exception\MessageException;
use Services\MessageService\Domain\Repository\MessageWriteTransactionInterface;

final readonly class PostgresMessageWriteTransaction implements MessageWriteTransactionInterface
{
    private PostgresMessageQuery $query;

    public function __construct(private PDO $pdo, private ?OperationBudget $budget = null)
    {
        $this->query = new PostgresMessageQuery($pdo, $budget);
    }

    public function findBySenderClientId(string $senderUserId, string $clientMessageId): ?array
    {
        return (new PostgresMessageRepository($this->pdo))->findBySenderClientId($senderUserId, $clientMessageId, $this->budget);
    }

    public function nextSequence(string $conversationId, string $now): string
    {
        return (string)$this->query->execute(
            'INSERT INTO conversation_sequences (conversation_id, last_seq, updated_at) VALUES (:conversation, 1, :now) '
            . 'ON CONFLICT (conversation_id) DO UPDATE SET last_seq = conversation_sequences.last_seq + 1, updated_at = EXCLUDED.updated_at RETURNING last_seq',
            ['conversation' => $conversationId, 'now' => $now],
        )->fetchColumn();
    }

    public function insert(array $message): void
    {
        $fields = [
            'message_id', 'conversation_id', 'seq', 'sender_user_id', 'sender_persona_id', 'sender_identity_mode',
            'sender_display_name', 'sender_avatar_key', 'sender_anonymous_ref', 'sender_scope_type', 'sender_scope_id',
            'client_message_id', 'type', 'content', 'created_at',
        ];
        $parameters = [];
        foreach ($fields as $field) {
            $parameters[$field] = $message[$field] ?? null;
        }
        $values = array_map(static fn (string $field): string => $field === 'content' ? 'CAST(:content AS jsonb)' : ':' . $field, $fields);
        try {
            $this->query->execute('INSERT INTO messages (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')', $parameters);
        } catch (PDOException $exception) {
            if (in_array(PostgresErrorClassifier::sqlState($exception), ['22P02', '22003', '22021', '22P05'], true)) {
                throw MessageException::invalid('message content cannot be represented as PostgreSQL JSONB');
            }
            throw $exception;
        }
    }

    public function recordMessageCreated(string $eventId, string $conversationId, string $messageId, string $sequence, array $payload, string $traceId, string $now): void
    {
        $this->query->execute(
            'INSERT INTO outbox_events (id, event_id, conversation_id, message_id, message_seq, aggregate_type, aggregate_id, '
            . 'event_key, event_type, event_version, trace_id, payload, status, available_at, created_at, attempt_count) '
            . "VALUES (:id, :event, :conversation, :message, :seq, 'conversation', :aggregate, :key, 'message.created.v1', 1, :trace, CAST(:payload AS jsonb), 0, :available, :created, 0)",
            [
                'id' => $messageId, 'event' => $eventId, 'conversation' => $conversationId, 'message' => $messageId,
                'seq' => $sequence, 'aggregate' => $conversationId, 'key' => $conversationId, 'trace' => $traceId,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'available' => $now, 'created' => $now,
            ],
        );
    }
}
