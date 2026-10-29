<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class CreatePostgresqlMessageStorage extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('CREATE TABLE message_db.conversation_sequences (
            conversation_id BIGINT PRIMARY KEY CHECK (conversation_id > 0),
            last_seq BIGINT NOT NULL CHECK (last_seq >= 0), updated_at TIMESTAMPTZ(3) NOT NULL
        )');
        $this->execute('CREATE TABLE message_db.messages (
            message_id BIGINT PRIMARY KEY CHECK (message_id > 0),
            conversation_id BIGINT NOT NULL CHECK (conversation_id > 0), seq BIGINT NOT NULL CHECK (seq > 0),
            sender_user_id BIGINT NOT NULL CHECK (sender_user_id > 0), sender_persona_id BIGINT NOT NULL CHECK (sender_persona_id > 0),
            sender_identity_mode SMALLINT NOT NULL CHECK (sender_identity_mode IN (1, 2)),
            sender_display_name VARCHAR(64), sender_avatar_key VARCHAR(255),
            sender_anonymous_ref VARCHAR(128) COLLATE "C", sender_scope_type VARCHAR(255) COLLATE "C", sender_scope_id VARCHAR(255) COLLATE "C",
            client_message_id VARCHAR(36) COLLATE "C" NOT NULL, type INTEGER NOT NULL CHECK (type > 0),
            content JSONB NOT NULL, created_at TIMESTAMPTZ(3) NOT NULL,
            CONSTRAINT uniq_conversation_seq UNIQUE (conversation_id, seq),
            CONSTRAINT uniq_sender_client_message UNIQUE (sender_user_id, client_message_id)
        )');
        $this->execute('CREATE TABLE message_db.outbox_events (
            id BIGINT PRIMARY KEY CHECK (id > 0), event_id VARCHAR(36) COLLATE "C" NOT NULL UNIQUE,
            conversation_id BIGINT NOT NULL, message_id BIGINT NOT NULL UNIQUE REFERENCES message_db.messages (message_id),
            message_seq BIGINT NOT NULL CHECK (message_seq > 0),
            aggregate_type VARCHAR(64) COLLATE "C" NOT NULL, aggregate_id VARCHAR(64) COLLATE "C" NOT NULL,
            event_key VARCHAR(64) COLLATE "C" NOT NULL, trace_id VARCHAR(64) COLLATE "C" NOT NULL,
            event_type VARCHAR(128) COLLATE "C" NOT NULL, event_version INTEGER NOT NULL DEFAULT 1,
            payload JSONB NOT NULL, status SMALLINT NOT NULL DEFAULT 0 CHECK (status IN (0, 1, 2)),
            available_at TIMESTAMPTZ(3) NOT NULL, claimed_at TIMESTAMPTZ(3), lease_until TIMESTAMPTZ(3),
            claim_token VARCHAR(64) COLLATE "C", published_at TIMESTAMPTZ(3), created_at TIMESTAMPTZ(3) NOT NULL,
            attempt_count INTEGER NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
            UNIQUE (conversation_id, message_seq),
            FOREIGN KEY (conversation_id, message_seq) REFERENCES message_db.messages (conversation_id, seq)
        )');
        $this->execute('CREATE INDEX outbox_events_pending ON message_db.outbox_events (status, available_at, id)');
        $this->execute('CREATE INDEX outbox_events_predecessor ON message_db.outbox_events (event_key, message_seq) WHERE status <> 1');
        $this->execute('CREATE INDEX outbox_events_lease ON message_db.outbox_events (lease_until) WHERE status = 2');
        $this->execute('GRANT SELECT, INSERT ON message_db.messages TO message_runtime');
        $this->execute('GRANT SELECT, INSERT, UPDATE ON message_db.conversation_sequences TO message_runtime');
        $this->execute('GRANT INSERT ON message_db.outbox_events TO message_runtime');
        $this->execute('GRANT SELECT, UPDATE ON message_db.outbox_events TO message_outbox_relay');
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Removing message storage can destroy messages and unpublished events.');
    }
}
