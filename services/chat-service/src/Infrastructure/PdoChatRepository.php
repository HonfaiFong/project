<?php

declare(strict_types=1);

namespace Services\ChatService\Infrastructure;

use PDO;
use PDOException;
use Services\ChatService\Domain\Exception\ChatException;
use Throwable;

/** PDO repository for the chat logical database. */
final class PdoChatRepository
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

    /** Create a complete local schema for SQLite/unit tests. Production uses Phinx. */
    public function ensureSchema(): void
    {
        if (!$this->isSqlite()) {
            throw new \RuntimeException('ChatService worker must not run DDL; apply Phinx migrations first.');
        }
        // This helper is deliberately SQLite-only. Production workers use
        // Phinx migrations and must never derive a schema at startup.
        $engine = '';
        $big = 'INTEGER';
        $text = 'TEXT';
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS conversations ('
            . 'id ' . $big . ' NOT NULL PRIMARY KEY,'
            . 'type INTEGER NOT NULL DEFAULT 1,'
            . 'last_message_id ' . $big . ' NULL,'
            . 'last_message_seq ' . $big . ' NOT NULL DEFAULT 0,'
            . 'last_message_at VARCHAR(40) NULL,'
            . 'created_at VARCHAR(40) NOT NULL,'
            . 'updated_at VARCHAR(40) NOT NULL,'
            . 'private_key ' . $text . ' NULL,'
            . 'creation_request_id VARCHAR(128) NULL,'
            . 'requesting_user_id ' . $big . ' NULL,'
            . 'target_mode INTEGER NOT NULL DEFAULT 1,'
            . 'target_user_id ' . $big . ' NULL,'
            . 'target_persona_id ' . $big . ' NULL,'
            . 'target_scope_type VARCHAR(255) NULL,'
            . 'target_scope_id VARCHAR(255) NULL,'
            . 'target_anonymous_ref VARCHAR(128) NULL'
            . ')' . $engine,
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS conversation_members ('
            . 'id ' . $big . ' NOT NULL PRIMARY KEY,'
            . 'conversation_id ' . $big . ' NOT NULL,'
            . 'user_id ' . $big . ' NOT NULL,'
            . 'persona_id ' . $big . ' NOT NULL,'
            . 'identity_mode INTEGER NOT NULL DEFAULT 0,'
            . 'display_name_snapshot VARCHAR(64) NULL,'
            . 'avatar_key_snapshot VARCHAR(255) NULL,'
            . 'anonymous_ref VARCHAR(128) NULL,'
            . 'scope_type VARCHAR(255) NULL,'
            . 'scope_id VARCHAR(255) NULL,'
            . 'last_read_seq ' . $big . ' NOT NULL DEFAULT 0,'
            . 'joined_at VARCHAR(40) NOT NULL,'
            . 'left_at VARCHAR(40) NULL,'
            . 'mute_until VARCHAR(40) NULL'
            . ')' . $engine,
        );
        $this->ensureColumn('conversations', 'private_key', $text . ' NULL');
        $this->ensureColumn('conversations', 'last_message_seq', $big . ' NOT NULL DEFAULT 0');
        $this->ensureColumn('conversations', 'creation_request_id', 'VARCHAR(128) NULL');
        $this->ensureColumn('conversations', 'requesting_user_id', $big . ' NULL');
        $this->ensureColumn('conversations', 'target_mode', 'INTEGER NOT NULL DEFAULT 1');
        $this->ensureColumn('conversations', 'target_user_id', $big . ' NULL');
        $this->ensureColumn('conversations', 'target_persona_id', $big . ' NULL');
        $this->ensureColumn('conversations', 'target_scope_type', 'VARCHAR(255) NULL');
        $this->ensureColumn('conversations', 'target_scope_id', 'VARCHAR(255) NULL');
        $this->ensureColumn('conversations', 'target_anonymous_ref', 'VARCHAR(128) NULL');
        foreach ([
            ['conversation_members', 'identity_mode', 'INTEGER NOT NULL DEFAULT 0'],
            ['conversation_members', 'display_name_snapshot', 'VARCHAR(64) NULL'],
            ['conversation_members', 'avatar_key_snapshot', 'VARCHAR(255) NULL'],
            ['conversation_members', 'anonymous_ref', 'VARCHAR(128) NULL'],
            ['conversation_members', 'scope_type', 'VARCHAR(255) NULL'],
            ['conversation_members', 'scope_id', 'VARCHAR(255) NULL'],
        ] as [$table, $column, $definition]) {
            $this->ensureColumn($table, $column, $definition);
        }

        // SQLite supports a UNIQUE index over nullable private_key values;
        // multiple legacy NULL rows remain valid while all new rows are keyed.
        $this->createIndex('CREATE UNIQUE INDEX IF NOT EXISTS uniq_chat_private_key ON conversations(private_key)');
        $this->createIndex('CREATE INDEX IF NOT EXISTS idx_chat_user_conversation ON conversation_members(user_id, conversation_id)');
        $this->createIndex('CREATE UNIQUE INDEX IF NOT EXISTS uniq_chat_conversation_user ON conversation_members(conversation_id, user_id)');
    }

    /** @return array<string, mixed>|null */
    public function findByPrivateKey(string $privateKey): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM conversations WHERE private_key = :private_key LIMIT 1');
        $statement->execute(['private_key' => $privateKey]);
        $row = $statement->fetch();

        return $row === false ? null : $this->withMembers($row);
    }

    /** @return array<string, mixed>|null */
    public function findByIdForUser(string $conversationId, string $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.* FROM conversations c '
            . 'INNER JOIN conversation_members m ON m.conversation_id = c.id '
            . 'WHERE c.id = :conversation_id AND m.user_id = :user_id AND m.left_at IS NULL LIMIT 1',
        );
        $statement->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->withMembers($row);
    }

    /** @return array<string, mixed>|null */
    public function findById(string $conversationId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM conversations WHERE id = :conversation_id LIMIT 1');
        $statement->execute(['conversation_id' => $conversationId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->withMembers($row);
    }

    /** @return array<string, mixed>|null */
    public function findMember(string $conversationId, string $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM conversation_members '
            . 'WHERE conversation_id = :conversation_id AND user_id = :user_id AND left_at IS NULL LIMIT 1',
        );
        $statement->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function listForUser(string $userId, int $limit, ?string $afterId = null): array
    {
        $sql = 'SELECT c.* FROM conversations c '
            . 'INNER JOIN conversation_members m ON m.conversation_id = c.id '
            . 'WHERE m.user_id = :user_id AND m.left_at IS NULL';
        $params = ['user_id' => $userId];
        if ($afterId !== null && $afterId !== '') {
            try {
                $decoded = base64_decode($afterId, true);
                $cursor = $decoded === false ? null : json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $cursor = null;
            }
            if (!is_array($cursor) || !is_string($cursor['at'] ?? null)
                || !is_string($cursor['id'] ?? null)
                || preg_match('/\A[1-9][0-9]*\z/', $cursor['id']) !== 1
                || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\z/', $cursor['at']) !== 1
            ) {
                throw ChatException::invalid('invalid conversation cursor');
            }
            $sql .= ' AND (COALESCE(c.last_message_at, c.created_at) < :after_at '
                . 'OR (COALESCE(c.last_message_at, c.created_at) = :equal_at AND c.id < :after_id))';
            // The cursor wire format is UTC without a suffix; PostgreSQL must
            // receive an explicit zone even if the session TimeZone differs.
            $cursorAt = $this->isSqlite() ? $cursor['at'] : $cursor['at'] . '+00:00';
            $params['after_at'] = $cursorAt;
            $params['equal_at'] = $cursorAt;
            $params['after_id'] = $cursor['id'];
        }
        $sql .= ' ORDER BY COALESCE(c.last_message_at, c.created_at) DESC, c.id DESC LIMIT ' . (int)($limit + 1);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = [];
        while ($row = $statement->fetch()) {
            $rows[] = $this->withMembers($row);
        }

        return $rows;
    }

    /** @param array<string, mixed> $conversation @param list<array<string,mixed>> $members */
    public function insert(array $conversation, array $members): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO conversations '
            . '(id, type, last_message_id, last_message_at, created_at, updated_at, private_key, creation_request_id, '
            . 'requesting_user_id, target_mode, target_user_id, target_persona_id, target_scope_type, target_scope_id, target_anonymous_ref) '
            . 'VALUES (:id, :type, NULL, NULL, :created_at, :updated_at, :private_key, :creation_request_id, '
            . ':requesting_user_id, :target_mode, :target_user_id, :target_persona_id, :target_scope_type, :target_scope_id, :target_anonymous_ref)',
        );
        $statement->execute([
            'id' => $conversation['id'],
            'type' => $conversation['type'] ?? 1,
            'created_at' => $conversation['created_at'],
            'updated_at' => $conversation['updated_at'],
            'private_key' => $conversation['private_key'],
            'creation_request_id' => $conversation['creation_request_id'] ?? null,
            'requesting_user_id' => $conversation['requesting_user_id'] ?? null,
            'target_mode' => $conversation['target_mode'],
            'target_user_id' => $conversation['target_user_id'] ?? null,
            'target_persona_id' => $conversation['target_persona_id'] ?? null,
            'target_scope_type' => $conversation['target_scope_type'] ?? null,
            'target_scope_id' => $conversation['target_scope_id'] ?? null,
            'target_anonymous_ref' => $conversation['target_anonymous_ref'] ?? null,
        ]);

        $memberStatement = $this->pdo->prepare(
            'INSERT INTO conversation_members '
            . '(id, conversation_id, user_id, persona_id, identity_mode, display_name_snapshot, avatar_key_snapshot, '
            . 'anonymous_ref, scope_type, scope_id, last_read_seq, joined_at, left_at, mute_until) '
            . 'VALUES (:id, :conversation_id, :user_id, :persona_id, :identity_mode, :display_name, :avatar_key, '
            . ':anonymous_ref, :scope_type, :scope_id, 0, :joined_at, NULL, NULL)',
        );
        foreach ($members as $member) {
            $memberStatement->execute([
                'id' => $member['id'],
                'conversation_id' => $conversation['id'],
                'user_id' => $member['user_id'],
                'persona_id' => $member['persona_id'],
                'identity_mode' => $member['identity_mode'],
                'display_name' => $member['display_name'] ?? null,
                'avatar_key' => $member['avatar_key'] ?? null,
                'anonymous_ref' => $member['anonymous_ref'] ?? null,
                'scope_type' => $member['scope_type'] ?? null,
                'scope_id' => $member['scope_id'] ?? null,
                'joined_at' => $member['joined_at'],
            ]);
        }
    }

    public function markRead(string $conversationId, string $userId, string $seq): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE conversation_members SET last_read_seq = CASE '
            . 'WHEN last_read_seq < :compare_seq THEN :seq ELSE last_read_seq END '
            . 'WHERE conversation_id = :conversation_id AND user_id = :user_id AND left_at IS NULL',
        );
        $statement->execute([
            'compare_seq' => $seq,
            'seq' => $seq,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
        ]);

        return $this->findMember($conversationId, $userId) !== null;
    }

    public function latestMessageSequence(string $conversationId): string
    {
        $statement = $this->pdo->prepare('SELECT last_message_seq FROM conversations WHERE id = :id');
        $statement->execute(['id' => $conversationId]);
        $sequence = $statement->fetchColumn();
        if ($sequence === false) {
            throw ChatException::notFound();
        }

        return (string)$sequence;
    }

    /** Apply only newer sequences; duplicate and out-of-order events cannot regress the summary. */
    public function applyMessageSummary(string $conversationId, string $messageId, string $seq, string $createdAt): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE conversations SET last_message_id = :message_id, last_message_seq = :seq, '
            . 'last_message_at = CASE WHEN last_message_at > :compare_at THEN last_message_at ELSE :created_at END, '
            . 'updated_at = CASE WHEN updated_at > :compare_updated THEN updated_at ELSE :updated_at END '
            . 'WHERE id = :conversation_id AND last_message_seq < :new_seq',
        );
        $statement->execute([
            'message_id' => $messageId, 'seq' => $seq, 'created_at' => $createdAt,
            'updated_at' => $createdAt, 'compare_at' => $createdAt, 'compare_updated' => $createdAt,
            'conversation_id' => $conversationId, 'new_seq' => $seq,
        ]);
    }

    /** @param array<string, mixed> $row */
    public function listCursor(array $row): string
    {
        return base64_encode(json_encode([
            'at' => (new \DateTimeImmutable(
                (string)($row['last_message_at'] ?? $row['created_at']),
                new \DateTimeZone('UTC'),
            ))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
            'id' => (string)$row['id'],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function withMembers(array $row): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM conversation_members WHERE conversation_id = :conversation_id AND left_at IS NULL ORDER BY id ASC',
        );
        $statement->execute(['conversation_id' => (string)$row['id']]);
        $row['members'] = $statement->fetchAll() ?: [];

        return $row;
    }

    private function isMysql(): bool
    {
        return str_starts_with(strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)), 'mysql');
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
            // Existing production schemas can have equivalent indexes under a
            // deployment-specific name. Reads/writes remain safe in that case.
            if (!str_contains(strtolower($e->getMessage()), 'already exists')) {
                throw $e;
            }
        }
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        if ($this->columnExists($table, $column)) {
            return;
        }
        $this->pdo->exec('ALTER TABLE ' . $this->identifier($table) . ' ADD COLUMN ' . $this->identifier($column) . ' ' . $definition);
    }

    private function columnExists(string $table, string $column): bool
    {
        if ($this->isMysql()) {
            $statement = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1',
            );
            $statement->execute(['table' => $table, 'column' => $column]);

            return $statement->fetchColumn() !== false;
        }
        $statement = $this->pdo->query('PRAGMA table_info(' . $this->identifier($table) . ')');
        while ($row = $statement->fetch()) {
            if ((string)$row['name'] === $column) {
                return true;
            }
        }

        return false;
    }

    private function identifier(string $identifier): string
    {
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $identifier) !== 1) {
            throw ChatException::invalid('invalid database identifier');
        }

        return '"' . $identifier . '"';
    }
}
