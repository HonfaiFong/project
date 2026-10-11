<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * Adds the durable key and sequence state used by the chat/message workers.
 *
 * Chat and message are separate logical databases.  This migration only
 * changes tables owned by those services; authorization and content lookup
 * continue to happen through gRPC and never through cross-database SQL.
 */
final class AddChatMessageSessionState extends AbstractMigration
{
    public function up(): void
    {
        $prefix = $this->schemaPrefix();
        $chat = $prefix . 'chat_db';
        $message = $prefix . 'message_db';

        // The application stores a SHA-256 hex digest.  Keeping this ASCII
        // and short avoids utf8mb4 collation folding and index-width limits.
        $this->addColumnIfMissing($chat, 'conversations', 'private_key', 'VARCHAR(64) NULL');
        $this->addColumnIfMissing($chat, 'conversations', 'creation_request_id', 'VARCHAR(128) NULL');
        $this->addColumnIfMissing($chat, 'conversations', 'requesting_user_id', 'BIGINT UNSIGNED NULL');
        $this->addColumnIfMissing($chat, 'conversations', 'target_mode', 'TINYINT UNSIGNED NOT NULL DEFAULT 1');
        $this->addColumnIfMissing($chat, 'conversations', 'target_user_id', 'BIGINT UNSIGNED NULL');
        $this->addColumnIfMissing($chat, 'conversations', 'target_persona_id', 'BIGINT UNSIGNED NULL');
        $this->addColumnIfMissing($chat, 'conversations', 'target_scope_type', 'VARCHAR(255) NULL');
        $this->addColumnIfMissing($chat, 'conversations', 'target_scope_id', 'VARCHAR(255) NULL');
        $this->addColumnIfMissing($chat, 'conversations', 'target_anonymous_ref', 'VARCHAR(128) NULL');

        $this->addIndexIfMissing($chat, 'conversations', 'uniq_private_key', '`private_key`');
        $this->addIndexIfMissing(
            $chat,
            'conversations',
            'idx_creation_request',
            '`requesting_user_id`, `creation_request_id`',
        );
        $this->execute(
            'CREATE TABLE IF NOT EXISTS ' . $this->identifier($message) . '.`conversation_sequences` ('
            . '`conversation_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY, '
            . '`last_seq` BIGINT UNSIGNED NOT NULL DEFAULT 0, '
            . '`updated_at` DATETIME(3) NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        // The snapshot migration owns these columns.  The checks make this
        // migration safe when a deployment replays migrations out of order.
        $this->addColumnIfMissing($message, 'messages', 'sender_scope_type', 'VARCHAR(255) NULL');
        $this->addColumnIfMissing($message, 'messages', 'sender_scope_id', 'VARCHAR(255) NULL');
        $this->addIndexIfMissing(
            $message,
            'messages',
            'idx_conv_seq_message',
            '`conversation_id`, `seq`, `message_id`',
        );
    }

    /** @throws IrreversibleMigrationException */
    public function down(): void
    {
        $prefix = $this->schemaPrefix();
        $chat = $prefix . 'chat_db';
        $message = $prefix . 'message_db';
        foreach ([[$chat, 'conversations'], [$message, 'messages'], [$message, 'conversation_sequences']] as [$schema, $table]) {
            if ($this->tableHasRows($schema, $table)) {
                throw new IrreversibleMigrationException(
                    'Cannot remove chat/message session state while ' . $schema . '.' . $table . ' contains rows.',
                );
            }
        }

        $this->dropColumnIfPresent($chat, 'conversations', [
            'private_key', 'creation_request_id', 'requesting_user_id', 'target_mode',
            'target_user_id', 'target_persona_id', 'target_scope_type', 'target_scope_id',
            'target_anonymous_ref',
        ]);
        // sender_scope_type/sender_scope_id belong to
        // AddAnonymousAuthorSnapshots (20261010000200).  This migration only
        // verifies them on up for deployments that apply migrations out of
        // order; its rollback must not remove another migration's columns.
        $this->execute('DROP TABLE IF EXISTS ' . $this->identifier($message) . '.`conversation_sequences`');
    }

    private function addColumnIfMissing(string $schema, string $table, string $column, string $definition): void
    {
        if ($this->getAdapter()->hasColumn($schema . '.' . $table, $column)) {
            return;
        }

        $this->execute(
            'ALTER TABLE ' . $this->identifier($schema) . '.`' . $table . '` '
            . 'ADD COLUMN `' . $column . '` ' . $definition,
        );
    }

    private function addIndexIfMissing(string $schema, string $table, string $name, string $definition): void
    {
        if ($this->getAdapter()->hasIndexByName($schema . '.' . $table, $name)) {
            return;
        }

        $kind = str_starts_with($name, 'uniq_') ? 'UNIQUE INDEX ' : 'INDEX ';
        $this->execute(
            'ALTER TABLE ' . $this->identifier($schema) . '.`' . $table . '` '
            . 'ADD ' . $kind . '`' . $name . '` (' . $definition . ')',
        );
    }

    /** @param list<string> $columns */
    private function dropColumnIfPresent(string $schema, string $table, array $columns): void
    {
        $clauses = [];
        foreach ($columns as $column) {
            if ($this->getAdapter()->hasColumn($schema . '.' . $table, $column)) {
                $clauses[] = 'DROP COLUMN `' . $column . '`';
            }
        }
        if ($clauses !== []) {
            $this->execute(
                'ALTER TABLE ' . $this->identifier($schema) . '.`' . $table . '` '
                . implode(', ', $clauses),
            );
        }
    }

    private function tableHasRows(string $schema, string $table): bool
    {
        if (!$this->getAdapter()->hasTable($schema . '.' . $table)) {
            return false;
        }

        $statement = $this->getAdapter()->getConnection()->query(
            'SELECT 1 FROM ' . $this->identifier($schema) . '.`' . $table . '` LIMIT 1',
        );

        return $statement->fetchColumn() !== false;
    }

    private function schemaPrefix(): string
    {
        $prefix = $this->getAdapter()->getOption('schema_prefix') ?? '';
        if (!is_string($prefix) || preg_match('/\A[a-zA-Z0-9_]*\z/', $prefix) !== 1) {
            throw new RuntimeException('schema_prefix must contain only ASCII letters, digits, and underscores.');
        }

        return $prefix;
    }

    private function identifier(string $identifier): string
    {
        return '`' . $identifier . '`';
    }
}
