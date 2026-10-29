<?php

declare(strict_types=1);

namespace Services\MessageService\Infrastructure\Postgres;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use PDO;
use RuntimeException;
use Throwable;

/** Offline import of canonical Extended JSON exports, without a legacy database driver. */
final readonly class MessageArchiveImporter
{
    public function __construct(private PDO $pdo) {}

    /** @return array{messages:int,sequences:int,outbox:int} */
    public function import(string $messages, string $sequences, string $outbox): array
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('LOCK TABLE messages, conversation_sequences, outbox_events IN ACCESS EXCLUSIVE MODE NOWAIT');
            foreach (['messages', 'conversation_sequences', 'outbox_events'] as $table) {
                if ((int)$this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) {
                    throw new RuntimeException('Archive import requires empty message tables; existing data was not changed');
                }
            }
            $counts = ['messages' => 0, 'sequences' => 0, 'outbox' => 0];
            $transaction = new PostgresMessageWriteTransaction($this->pdo);
            foreach ($this->documents($messages) as $document) {
                $this->requireFields($document, ['_id', 'conversation_id', 'seq', 'sender_user_id', 'sender_persona_id',
                    'sender_identity_mode', 'client_message_id', 'type', 'content', 'created_at']);
                if ($this->decimal($document['schema_version'] ?? 1) !== '1') {
                    throw new RuntimeException('Unsupported archive message schema version');
                }
                $message = $document;
                $message['message_id'] = $this->decimal($document['_id']);
                foreach (['conversation_id', 'seq', 'sender_user_id', 'sender_persona_id', 'sender_identity_mode', 'type'] as $field) {
                    $message[$field] = $this->decimal($document[$field]);
                }
                $message['created_at'] = $this->date($document['created_at']);
                $transaction->insert($message);
                $counts['messages']++;
            }
            $insertSequence = $this->pdo->prepare('INSERT INTO conversation_sequences (conversation_id,last_seq,updated_at) VALUES (:conversation,:seq,:at)');
            foreach ($this->documents($sequences) as $document) {
                $this->requireFields($document, ['_id', 'last_seq', 'updated_at']);
                $insertSequence->execute(['conversation' => $this->decimal($document['_id']),
                    'seq' => $this->decimal($document['last_seq']), 'at' => $this->date($document['updated_at'])]);
                $counts['sequences']++;
            }
            $restoreState = $this->pdo->prepare('UPDATE outbox_events SET status=:status,available_at=:available,
                published_at=:published,attempt_count=:attempts WHERE event_id=:event');
            foreach ($this->documents($outbox) as $document) {
                $this->requireFields($document, ['_id', 'conversation_id', 'message_id', 'message_seq', 'payload',
                    'trace_id', 'event_type', 'status', 'available_at', 'created_at']);
                $event = (string)$document['_id'];
                $conversation = $this->decimal($document['conversation_id']);
                $message = $this->decimal($document['message_id']);
                $seq = $this->decimal($document['message_seq']);
                $payload = $this->payload($document['payload']);
                if (!is_array($payload) || (string)($payload['message']['message_id'] ?? '') !== $message
                    || (string)($payload['message']['conversation_id'] ?? '') !== $conversation
                    || (string)($payload['message']['seq'] ?? '') !== $seq
                    || ($document['event_type'] ?? '') !== 'message.created.v1'
                    || $this->decimal($document['event_version'] ?? 1) !== '1') {
                    throw new RuntimeException('Archive event does not match its message identity and sequence');
                }
                $transaction->recordMessageCreated($event, $conversation, $message, $seq, $payload,
                    (string)$document['trace_id'], $this->date($document['created_at']));
                $status = $this->decimal($document['status']);
                if (!in_array($status, ['0', '1', '2'], true)) {
                    throw new RuntimeException('Unsupported archive Outbox status');
                }
                // Old relay claims must be requeued. Duplicate publication is allowed
                // by the existing at-least-once contract; losing an event is not.
                $restoreState->execute(['status' => $status === '1' ? 1 : 0,
                    'available' => $this->date($document['available_at']),
                    'published' => isset($document['published_at']) ? $this->date($document['published_at']) : null,
                    'attempts' => $this->decimal($document['attempt_count'] ?? 0), 'event' => $event]);
                $counts['outbox']++;
            }
            $mismatches = (int)$this->pdo->query('SELECT COUNT(*) FROM conversation_sequences s FULL JOIN
                (SELECT conversation_id,MAX(seq) AS last_seq FROM messages GROUP BY conversation_id) m USING (conversation_id)
                WHERE s.last_seq IS DISTINCT FROM COALESCE(m.last_seq,0)')->fetchColumn();
            $eventMismatches = (int)$this->pdo->query('SELECT COUNT(*) FROM outbox_events o JOIN messages m ON m.message_id=o.message_id
                WHERE o.conversation_id<>m.conversation_id OR o.message_seq<>m.seq')->fetchColumn();
            if ($mismatches !== 0 || $eventMismatches !== 0 || $counts['outbox'] !== $counts['messages']) {
                throw new RuntimeException('Archive counts or committed conversation sequences do not match; import rolled back');
            }
            $this->pdo->commit();
            return $counts;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    /** @return Generator<int,array<string,mixed>> */
    private function documents(string $path): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) { throw new RuntimeException('Cannot read message archive: ' . $path); }
        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') { continue; }
                $document = json_decode($line, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                if (!is_array($document)) { throw new RuntimeException('Archive lines must be JSON documents'); }
                yield $document;
            }
            if (!feof($handle)) { throw new RuntimeException('Message archive read failed'); }
        } finally {
            fclose($handle);
        }
    }

    private function decimal(mixed $value): string
    {
        if (is_array($value)) { $value = $value['$numberLong'] ?? $value['$numberInt'] ?? null; }
        if ((!is_string($value) && !is_int($value)) || preg_match('/\A(?:0|[1-9][0-9]*)\z/', (string)$value) !== 1) {
            throw new RuntimeException('Archive contains an invalid integer');
        }
        $decimal = (string)$value;
        if (strlen($decimal) > 19 || (strlen($decimal) === 19 && strcmp($decimal, '9223372036854775807') > 0)) {
            throw new RuntimeException('Archive integer exceeds signed BIGINT');
        }
        return $decimal;
    }

    /** @param array<string,mixed> $document @param list<string> $fields */
    private function requireFields(array $document, array $fields): void
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $document)) { throw new RuntimeException('Archive document is missing field: ' . $field); }
        }
    }

    private function payload(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        if (count($value) === 1 && array_key_exists('$numberInt', $value)) {
            return (int)$this->decimal($value);
        }
        if (count($value) === 1 && array_key_exists('$numberLong', $value)) {
            return $this->decimal($value);
        }
        foreach ($value as $key => $child) { $value[$key] = $this->payload($child); }
        return $value;
    }

    private function date(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['$date'] ?? null;
            if (is_array($value)) {
                $ms = (int)$this->decimal($value);
                return (new DateTimeImmutable('@' . intdiv($ms, 1000)))->format('Y-m-d H:i:s') . sprintf('.%03d', $ms % 1000);
            }
        }
        if (!is_string($value) || $value === '') { throw new RuntimeException('Archive contains an invalid date'); }
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
