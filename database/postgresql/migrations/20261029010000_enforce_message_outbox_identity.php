<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class EnforceMessageOutboxIdentity extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE message_db.messages ADD CONSTRAINT uniq_message_identity UNIQUE (message_id, conversation_id, seq)');
        $this->execute('ALTER TABLE message_db.outbox_events ADD CONSTRAINT outbox_message_identity '
            . 'FOREIGN KEY (message_id, conversation_id, message_seq) '
            . 'REFERENCES message_db.messages (message_id, conversation_id, seq) NOT VALID');
        $this->execute('ALTER TABLE message_db.outbox_events VALIDATE CONSTRAINT outbox_message_identity');
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Message/Outbox identity protection must not be removed.');
    }
}
