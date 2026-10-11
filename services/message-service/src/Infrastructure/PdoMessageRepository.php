<?php

declare(strict_types=1);

namespace Services\MessageService\Infrastructure;

use JsonException;
use PDO;
use PDOException;
use RuntimeException;

/** PDO repository for message_db. It never reads chat_db directly. */
final class PdoMessageRepository
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** SQLite-only helper for isolated tests. Production schema comes from Phinx. */
    public function ensureSchema(): void
    {
        if (!$this->isSqlite()) {
            throw new RuntimeException('MessageService worker must not run DDL; apply Phinx migrations first.');
        }
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS messages ('
            . 'message_id INTEGER NOT NULL PRIMARY KEY,'
            . 'conversation_id INTEGER NOT NULL,'
            . 'seq INTEGER NOT NULL,'
            . 'sender_user_id INTEGER NOT NULL,'
            . 'sender_persona_id INTEGER NOT NULL,'
            . 'sender_identity_mode INTEGER NOT NULL DEFAULT 0,'
            . 'sender_display_name VARCHAR(64) NULL,'
            . 'sender_avatar_key VARCHAR(255) NULL,'
            . 'sender_anonymous_ref VARCHAR(128) NULL,'
            . 'sender_scope_type VARCHAR(255) NULL,'
            . 'sender_scope_id VARCHAR(255) NULL,'
            . 'client_message_id VARCHAR(36) NOT NULL,'
            . 'type INTEGER NOT NULL DEFAULT 1,'
            . 'content TEXT NOT NULL,'
            . 'created_at VARCHAR(40) NOT NULL'
            . ')',
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS conversation_sequences ('
            . 'conversation_id INTEGER NOT NULL PRIMARY KEY,'
            . 'last_seq INTEGER NOT NULL DEFAULT 0,'
            . 'updated_at VARCHAR(40) NOT NULL'
            . ')',
        );
        foreach ([
            ['sender_identity_mode', 'INTEGER NOT NULL DEFAULT 0'],
            ['sender_display_name', 'VARCHAR(64) NULL'],
            ['sender_avatar_key', 'VARCHAR(255) NULL'],
            ['sender_anonymous_ref', 'VARCHAR(128) NULL'],
            ['sender_scope_type', 'VARCHAR(255) NULL'],
            ['sender_scope_id', 'VARCHAR(255) NULL'],
        ] as [$column, $definition]) {
            $this->ensureColumn('messages', $column, $definition);
        }
        $this->createIndex('CREATE UNIQUE INDEX IF NOT EXISTS uniq_message_conv_seq ON messages(conversation_id, seq)');
        $this->createIndex('CREATE UNIQUE INDEX IF NOT EXISTS uniq_message_sender_client ON messages(sender_user_id, client_message_id)');
        $this->createIndex('CREATE INDEX IF NOT EXISTS idx_message_conv_id ON messages(conversation_id, message_id)');
    }

    /** @return array<string,mixed>|null */
    public function findBySenderClientId(string $senderUserId, string $clientMessageId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM messages WHERE sender_user_id = :sender_user_id AND client_message_id = :client_message_id LIMIT 1',
        );
        $statement->execute(['sender_user_id' => $senderUserId, 'client_message_id' => $clientMessageId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** Allocate a seq while the caller holds a transaction. */
    public function nextSequence(string $conversationId, string $now): string
    {
        if ($this->isSqlite()) {
            $statement = $this->pdo->prepare(
                'INSERT OR IGNORE INTO conversation_sequences (conversation_id, last_seq, updated_at) '
                . 'VALUES (:conversation_id, 0, :updated_at)',
            );
            $statement->execute(['conversation_id' => $conversationId, 'updated_at' => $now]);
        } else {
            $statement = $this->pdo->prepare(
                'INSERT INTO conversation_sequences (conversation_id, last_seq, updated_at) '
                . 'VALUES (:conversation_id, 0, :updated_at) '
                . 'ON DUPLICATE KEY UPDATE conversation_id = VALUES(conversation_id)',
            );
            $statement->execute(['conversation_id' => $conversationId, 'updated_at' => $now]);
        }

        $lock = $this->isSqlite() ? '' : ' FOR UPDATE';
        $select = $this->pdo->prepare(
            'SELECT last_seq FROM conversation_sequences WHERE conversation_id = :conversation_id' . $lock,
        );
        $select->execute(['conversation_id' => $conversationId]);
        $current = $select->fetchColumn();
        if ($current === false) {
            throw new RuntimeException('conversation sequence row is missing');
        }
        $next = $this->addOne((string)$current);
        $update = $this->pdo->prepare(
            'UPDATE conversation_sequences SET last_seq = :last_seq, updated_at = :updated_at '
            . 'WHERE conversation_id = :conversation_id',
        );
        $update->execute([
            'last_seq' => $next,
            'updated_at' => $now,
            'conversation_id' => $conversationId,
        ]);

        return $next;
    }

    /** @param array<string,mixed> $message */
    public function insert(array $message): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO messages ('
            . 'message_id, conversation_id, seq, sender_user_id, sender_persona_id, sender_identity_mode, '
            . 'sender_display_name, sender_avatar_key, sender_anonymous_ref, sender_scope_type, sender_scope_id, '
            . 'client_message_id, type, content, created_at'
            . ') VALUES ('
            . ':message_id, :conversation_id, :seq, :sender_user_id, :sender_persona_id, :sender_identity_mode, '
            . ':sender_display_name, :sender_avatar_key, :sender_anonymous_ref, :sender_scope_type, :sender_scope_id, '
            . ':client_message_id, :type, :content, :created_at)',
        );
        $statement->execute([
            'message_id' => $message['message_id'],
            'conversation_id' => $message['conversation_id'],
            'seq' => $message['seq'],
            'sender_user_id' => $message['sender_user_id'],
            'sender_persona_id' => $message['sender_persona_id'],
            'sender_identity_mode' => $message['sender_identity_mode'],
            'sender_display_name' => $message['sender_display_name'] ?? null,
            'sender_avatar_key' => $message['sender_avatar_key'] ?? null,
            'sender_anonymous_ref' => $message['sender_anonymous_ref'] ?? null,
            'sender_scope_type' => $message['sender_scope_type'] ?? null,
            'sender_scope_id' => $message['sender_scope_id'] ?? null,
            'client_message_id' => $message['client_message_id'],
            'type' => $message['type'],
            'content' => $message['content'],
            'created_at' => $message['created_at'],
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function listAfter(string $conversationId, string $afterSeq, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM messages WHERE conversation_id = :conversation_id AND seq > :after_seq '
            . 'ORDER BY seq ASC LIMIT ' . (int)($limit + 1),
        );
        $statement->execute(['conversation_id' => $conversationId, 'after_seq' => $afterSeq]);

        return $statement->fetchAll() ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function listPage(string $conversationId, int $limit, ?string $cursor): array
    {
        $sql = 'SELECT * FROM messages WHERE conversation_id = :conversation_id';
        $params = ['conversation_id' => $conversationId];
        if ($cursor !== null && $cursor !== '') {
            $sql .= ' AND message_id < :cursor';
            $params['cursor'] = $cursor;
        }
        $sql .= ' ORDER BY message_id DESC LIMIT ' . (int)($limit + 1);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }

    public function latestSequence(string $conversationId): string
    {
        $statement = $this->pdo->prepare('SELECT MAX(seq) FROM messages WHERE conversation_id = :conversation_id');
        $statement->execute(['conversation_id' => $conversationId]);

        return (string)($statement->fetchColumn() ?: '0');
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $request
     */
    public function idempotencyMatches(array $existing, array $request): bool
    {
        $existingContent = $this->canonicalJson($existing['content'] ?? null);
        $requestedContent = $this->canonicalJson($request['content'] ?? null);

        return (string)$existing['conversation_id'] === (string)$request['conversation_id']
            && (int)$existing['type'] === (int)$request['type']
            && (($existingContent !== null && $requestedContent !== null)
                ? $existingContent === $requestedContent
                : (string)$existing['content'] === (string)$request['content']);
    }

    private function canonicalJson(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        try {
            // Decode objects as stdClass so an empty object remains distinct
            // from an empty array. JSON object member order is insignificant,
            // so normalize every object recursively before encoding it.
            $decoded = json_decode($value, false, 512, JSON_THROW_ON_ERROR);

            return json_encode(
                $this->normalizeJsonValue($decoded),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException) {
            return null;
        }
    }

    private function normalizeJsonValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            uksort($properties, static fn (string $left, string $right): int => strcmp($left, $right));
            foreach ($properties as $key => $child) {
                $properties[$key] = $this->normalizeJsonValue($child);
            }

            return (object)$properties;
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->normalizeJsonValue($child);
        }

        return $value;
    }

    private function addOne(string $value): string
    {
        if (function_exists('bcadd')) {
            return bcadd($value, '1', 0);
        }
        if (strlen($value) >= 18) {
            $digits = str_split($value);
            for ($i = count($digits) - 1; $i >= 0; $i--) {
                if ($digits[$i] === '9') {
                    $digits[$i] = '0';
                    continue;
                }
                $digits[$i] = (string)((int)$digits[$i] + 1);
                return ltrim(implode('', $digits), '0') ?: '0';
            }
            return '1' . implode('', $digits);
        }

        return (string)((int)$value + 1);
    }

    private function isSqlite(): bool
    {
        return strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'sqlite';
    }

    private function createIndex(string $sql): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'already exists')) {
                throw $e;
            }
        }
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $statement = $this->pdo->query("PRAGMA table_info('" . $table . "')");
        $columns = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN, 1);
        if (in_array($column, $columns, true)) {
            return;
        }

        $this->pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
    }
}
