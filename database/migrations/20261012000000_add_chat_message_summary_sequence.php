<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class AddChatMessageSummarySequence extends AbstractMigration
{
    public function up(): void
    {
        $prefix = $this->getAdapter()->getOption('schema_prefix') ?? '';
        if (!is_string($prefix) || preg_match('/\A[a-zA-Z0-9_]*\z/', $prefix) !== 1 || strlen($prefix) > 54) {
            throw new RuntimeException('Invalid schema prefix');
        }
        // Cross-schema ALTER follows the existing Phinx migrations and uses a validated identifier.
        $schema = $prefix . 'chat_db';
        $pdo = $this->getAdapter()->getConnection();
        $statement = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = :schema '
            . "AND table_name = 'conversations' AND column_name = 'last_message_seq'");
        $statement->execute(['schema' => $schema]);
        if ($statement->fetchColumn() === false) {
            $this->execute('ALTER TABLE `' . $schema . '`.`conversations` '
                . 'ADD COLUMN `last_message_seq` BIGINT UNSIGNED NOT NULL DEFAULT 0');
        }
        // The coordinated migration role can seed historical summaries across logical schemas;
        // runtime workers still use events and never read another service's database.
        // Run even after a partial ALTER succeeded, because MySQL DDL commits independently.
        $messageSchema = $prefix . 'message_db';
        $this->execute('UPDATE `' . $schema . '`.`conversations` c '
            . 'JOIN (SELECT conversation_id, MAX(seq) AS latest_seq FROM `' . $messageSchema . '`.`messages` '
            . 'GROUP BY conversation_id) latest ON latest.conversation_id = c.id '
            . 'JOIN `' . $messageSchema . '`.`messages` m ON m.conversation_id = c.id AND m.seq = latest.latest_seq '
            . 'SET c.last_message_seq = m.seq, c.last_message_id = m.message_id, c.last_message_at = m.created_at '
            . 'WHERE c.last_message_seq < m.seq');
        $this->execute('UPDATE `' . $schema . '`.`conversation_members` member '
            . 'JOIN `' . $schema . '`.`conversations` c ON c.id = member.conversation_id '
            . 'SET member.last_read_seq = LEAST(member.last_read_seq, c.last_message_seq)');
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Removing the confirmed read upper bound would weaken message consistency');
    }
}
