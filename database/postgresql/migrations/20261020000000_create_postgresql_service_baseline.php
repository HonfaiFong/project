<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** Fresh PostgreSQL baseline; legacy MySQL migrations remain isolated in database/migrations. */
final class CreatePostgresqlServiceBaseline extends AbstractMigration
{
    /** @var list<string> */
    private const SCHEMAS = ['auth_db', 'user_db', 'content_db', 'social_db', 'chat_db', 'meta'];

    public function up(): void
    {
        $prefix = $this->schemaPrefix();
        /** @var \PDO $pdo */
        $pdo = $this->getAdapter()->getConnection();
        foreach (self::SCHEMAS as $schema) {
            $name = $prefix . $schema;
            if (strlen($name) > 63) {
                throw new RuntimeException('PostgreSQL schema name exceeds 63 bytes: ' . $name);
            }
            $statement = $pdo->prepare('SELECT to_regnamespace(:schema)');
            $statement->execute(['schema' => $name]);
            $reg = $statement->fetchColumn();
            if ($reg === null || $reg === false) {
                throw new RuntimeException('Required PostgreSQL schema does not exist: ' . $name);
            }
        }

        $auth = $this->id($prefix . 'auth_db');
        $user = $this->id($prefix . 'user_db');
        $content = $this->id($prefix . 'content_db');
        $social = $this->id($prefix . 'social_db');
        $chat = $this->id($prefix . 'chat_db');

        // ICU level-1 equality preserves the previous case/accent-insensitive email uniqueness
        // explicitly. Identity and protocol keys below use deterministic C collation instead.
        $this->execute("CREATE COLLATION {$user}.email_ci_ai (provider = icu, locale = 'und-u-ks-level1', deterministic = false)");

        $statements = [
            "CREATE TABLE {$auth}.devices (
                id BIGINT PRIMARY KEY CHECK (id > 0), user_id BIGINT NOT NULL CHECK (user_id > 0),
                device_uuid VARCHAR(64) COLLATE \"C\" NOT NULL, platform VARCHAR(16) NOT NULL,
                app_version VARCHAR(32) NOT NULL, push_token VARCHAR(255), last_ip BYTEA,
                last_active_at TIMESTAMPTZ(3) NOT NULL, created_at TIMESTAMPTZ(3) NOT NULL, updated_at TIMESTAMPTZ(3) NOT NULL,
                CONSTRAINT uniq_user_device_uuid UNIQUE (user_id, device_uuid)
            )",
            "CREATE INDEX devices_idx_user_id ON {$auth}.devices (user_id)",
            "CREATE TABLE {$auth}.refresh_tokens (
                id BIGINT PRIMARY KEY CHECK (id > 0), user_id BIGINT NOT NULL CHECK (user_id > 0), device_id BIGINT NOT NULL CHECK (device_id > 0),
                token_hash CHAR(64) COLLATE \"C\" NOT NULL, expires_at TIMESTAMPTZ(3) NOT NULL,
                revoked_at TIMESTAMPTZ(6), logged_out_at TIMESTAMPTZ(6), created_at TIMESTAMPTZ(3) NOT NULL,
                CONSTRAINT uniq_token_hash UNIQUE (token_hash)
            )",
            "CREATE INDEX refresh_tokens_idx_user_device ON {$auth}.refresh_tokens (user_id, device_id)",
            "CREATE INDEX refresh_tokens_idx_expires_at ON {$auth}.refresh_tokens (expires_at)",
            "CREATE TABLE {$auth}.social_identities (
                id BIGINT PRIMARY KEY CHECK (id > 0), user_id BIGINT,
                provider VARCHAR(16) COLLATE \"C\" NOT NULL, provider_app_id VARCHAR(64) COLLATE \"C\" NOT NULL,
                identity_namespace VARCHAR(64) COLLATE \"C\" NOT NULL, subject_type VARCHAR(16) COLLATE \"C\" NOT NULL,
                subject_id VARCHAR(128) COLLATE \"C\" NOT NULL, openid VARCHAR(128) COLLATE \"C\" NOT NULL,
                unionid VARCHAR(128) COLLATE \"C\", status SMALLINT NOT NULL DEFAULT 0,
                bound_at TIMESTAMPTZ(3), last_login_at TIMESTAMPTZ(3), created_at TIMESTAMPTZ(3) NOT NULL, updated_at TIMESTAMPTZ(3) NOT NULL,
                CONSTRAINT uniq_social_subject UNIQUE (provider, identity_namespace, subject_type, subject_id),
                CONSTRAINT uniq_provider_openid UNIQUE (provider, provider_app_id, openid),
                CONSTRAINT uniq_user_provider UNIQUE (user_id, provider)
            )",
            "CREATE INDEX social_identities_idx_social_user ON {$auth}.social_identities (user_id)",
            "CREATE INDEX social_identities_idx_social_status ON {$auth}.social_identities (status)",
            "CREATE TABLE {$auth}.social_identity_aliases (
                provider VARCHAR(16) COLLATE \"C\" NOT NULL, provider_app_id VARCHAR(64) COLLATE \"C\" NOT NULL,
                openid VARCHAR(128) COLLATE \"C\" NOT NULL, social_identity_id BIGINT NOT NULL CHECK (social_identity_id > 0),
                CONSTRAINT social_identity_aliases_pkey PRIMARY KEY (provider, provider_app_id, openid),
                CONSTRAINT social_identity_aliases_identity_fk FOREIGN KEY (social_identity_id) REFERENCES {$auth}.social_identities(id)
            )",
            "CREATE INDEX social_identity_aliases_idx_identity ON {$auth}.social_identity_aliases (social_identity_id)",

            "CREATE TABLE {$user}.users (
                id BIGINT PRIMARY KEY CHECK (id > 0), status SMALLINT NOT NULL DEFAULT 1,
                phone VARCHAR(32) COLLATE \"C\", email VARCHAR(191) COLLATE {$user}.email_ci_ai,
                register_channel VARCHAR(32) NOT NULL DEFAULT 'default', risk_level SMALLINT NOT NULL DEFAULT 0,
                social_registration_key VARCHAR(128) COLLATE \"C\", last_login_at TIMESTAMPTZ(3), last_active_at TIMESTAMPTZ(3),
                created_at TIMESTAMPTZ(3) NOT NULL, updated_at TIMESTAMPTZ(3) NOT NULL, deleted_at TIMESTAMPTZ(3),
                CONSTRAINT uniq_phone UNIQUE (phone), CONSTRAINT uniq_email UNIQUE (email),
                CONSTRAINT uniq_social_registration_key UNIQUE (social_registration_key)
            )",
            "CREATE INDEX users_idx_status ON {$user}.users (status)",
            "CREATE INDEX users_idx_created_at ON {$user}.users (created_at)",
            "CREATE TABLE {$user}.personas (
                id BIGINT PRIMARY KEY CHECK (id > 0), user_id BIGINT NOT NULL CHECK (user_id > 0),
                type SMALLINT NOT NULL DEFAULT 1, is_internal_alias SMALLINT NOT NULL DEFAULT 0,
                nickname VARCHAR(64) NOT NULL, avatar_key VARCHAR(255), bio VARCHAR(500), status SMALLINT NOT NULL DEFAULT 1,
                created_at TIMESTAMPTZ(3) NOT NULL, updated_at TIMESTAMPTZ(3) NOT NULL, deleted_at TIMESTAMPTZ(3),
                CONSTRAINT chk_persona_type CHECK (type IN (1, 2)),
                CONSTRAINT chk_internal_alias_type CHECK (is_internal_alias IN (0, 1) AND (is_internal_alias = 0 OR type = 2))
            )",
            "CREATE UNIQUE INDEX personas_main_user_unique ON {$user}.personas (user_id) WHERE type = 1",
            "CREATE UNIQUE INDEX personas_internal_alias_user_unique ON {$user}.personas (user_id) WHERE type = 2 AND is_internal_alias = 1",
            "CREATE INDEX personas_idx_user_status ON {$user}.personas (user_id, status)",
            "CREATE INDEX personas_idx_user_type_status ON {$user}.personas (user_id, type, status, deleted_at)",
            "CREATE INDEX personas_idx_created_at ON {$user}.personas (created_at)",

            "CREATE TABLE {$content}.posts (
                id BIGINT PRIMARY KEY CHECK (id > 0), author_user_id BIGINT, request_id VARCHAR(64) COLLATE \"C\",
                persona_id BIGINT NOT NULL CHECK (persona_id > 0), identity_mode SMALLINT NOT NULL DEFAULT 0,
                author_display_name VARCHAR(64), author_avatar_key VARCHAR(255), author_anonymous_ref VARCHAR(128) COLLATE \"C\",
                author_scope_type VARCHAR(255) COLLATE \"C\", author_scope_id VARCHAR(255) COLLATE \"C\",
                type SMALLINT NOT NULL DEFAULT 1, content TEXT, visibility SMALLINT NOT NULL DEFAULT 1, emotion VARCHAR(32),
                status SMALLINT NOT NULL DEFAULT 1, moderation_status SMALLINT NOT NULL DEFAULT 1,
                like_count BIGINT NOT NULL DEFAULT 0 CHECK (like_count >= 0), comment_count BIGINT NOT NULL DEFAULT 0 CHECK (comment_count >= 0),
                view_count BIGINT NOT NULL DEFAULT 0 CHECK (view_count >= 0), published_at TIMESTAMPTZ(3),
                created_at TIMESTAMPTZ(3) NOT NULL, updated_at TIMESTAMPTZ(3) NOT NULL, deleted_at TIMESTAMPTZ(3),
                CONSTRAINT posts_uniq_author_request UNIQUE (author_user_id, request_id)
            )",
            "CREATE INDEX posts_idx_author_user_created ON {$content}.posts (author_user_id, created_at)",
            "CREATE INDEX posts_idx_alias_scope_exact ON {$content}.posts (author_scope_type, author_scope_id, author_anonymous_ref)",
            "CREATE INDEX posts_idx_persona_created ON {$content}.posts (persona_id, created_at)",
            "CREATE INDEX posts_idx_status_published ON {$content}.posts (status, published_at)",
            "CREATE INDEX posts_idx_moderation_created ON {$content}.posts (moderation_status, created_at)",
            "CREATE TABLE {$content}.post_media (
                id BIGINT PRIMARY KEY CHECK (id > 0), post_id BIGINT NOT NULL CHECK (post_id > 0), type SMALLINT NOT NULL DEFAULT 1,
                object_key VARCHAR(512) NOT NULL, mime_type VARCHAR(128) NOT NULL, width INTEGER, height INTEGER,
                duration_ms INTEGER, sort_order INTEGER NOT NULL DEFAULT 0, created_at TIMESTAMPTZ(3) NOT NULL
            )",
            "CREATE INDEX post_media_idx_post_sort ON {$content}.post_media (post_id, sort_order)",
            "CREATE TABLE {$content}.comments (
                id BIGINT PRIMARY KEY CHECK (id > 0), post_id BIGINT NOT NULL CHECK (post_id > 0), author_user_id BIGINT,
                request_id VARCHAR(64) COLLATE \"C\", persona_id BIGINT NOT NULL CHECK (persona_id > 0), identity_mode SMALLINT NOT NULL DEFAULT 0,
                author_display_name VARCHAR(64), author_avatar_key VARCHAR(255), author_anonymous_ref VARCHAR(128) COLLATE \"C\",
                author_scope_type VARCHAR(255) COLLATE \"C\", author_scope_id VARCHAR(255) COLLATE \"C\",
                parent_id BIGINT, reply_to_comment_id BIGINT, content VARCHAR(2000) NOT NULL, status SMALLINT NOT NULL DEFAULT 1,
                like_count BIGINT NOT NULL DEFAULT 0 CHECK (like_count >= 0), created_at TIMESTAMPTZ(3) NOT NULL,
                updated_at TIMESTAMPTZ(3) NOT NULL, deleted_at TIMESTAMPTZ(3),
                CONSTRAINT comments_uniq_author_request UNIQUE (author_user_id, request_id)
            )",
            "CREATE INDEX comments_idx_author_user_created ON {$content}.comments (author_user_id, created_at)",
            "CREATE INDEX comments_idx_alias_scope_exact ON {$content}.comments (author_scope_type, author_scope_id, author_anonymous_ref)",
            "CREATE INDEX comments_idx_post_created ON {$content}.comments (post_id, created_at)",
            "CREATE INDEX comments_idx_parent_created ON {$content}.comments (parent_id, created_at)",
            "CREATE INDEX comments_idx_persona_created ON {$content}.comments (persona_id, created_at)",

            "CREATE TABLE {$social}.likes (
                id BIGINT PRIMARY KEY CHECK (id > 0), persona_id BIGINT NOT NULL CHECK (persona_id > 0),
                target_type SMALLINT NOT NULL, target_id BIGINT NOT NULL CHECK (target_id > 0), created_at TIMESTAMPTZ(3) NOT NULL,
                CONSTRAINT chk_like_target_type CHECK (target_type IN (1, 2)),
                CONSTRAINT uniq_persona_target UNIQUE (persona_id, target_type, target_id)
            )",
            "CREATE INDEX likes_idx_target_created ON {$social}.likes (target_type, target_id, created_at)",
            "CREATE TABLE {$social}.follows (
                id BIGINT PRIMARY KEY CHECK (id > 0), follower_persona_id BIGINT NOT NULL CHECK (follower_persona_id > 0),
                followee_persona_id BIGINT NOT NULL CHECK (followee_persona_id > 0), created_at TIMESTAMPTZ(3) NOT NULL,
                CONSTRAINT chk_follow_not_self CHECK (follower_persona_id <> followee_persona_id),
                CONSTRAINT uniq_follow UNIQUE (follower_persona_id, followee_persona_id)
            )",
            "CREATE INDEX follows_idx_followee_created ON {$social}.follows (followee_persona_id, created_at)",
            "CREATE TABLE {$social}.blocks (
                id BIGINT PRIMARY KEY CHECK (id > 0), blocker_user_id BIGINT NOT NULL CHECK (blocker_user_id > 0),
                blocked_user_id BIGINT NOT NULL CHECK (blocked_user_id > 0), created_at TIMESTAMPTZ(3) NOT NULL,
                CONSTRAINT chk_block_not_self CHECK (blocker_user_id <> blocked_user_id),
                CONSTRAINT uniq_block UNIQUE (blocker_user_id, blocked_user_id)
            )",
            "CREATE INDEX blocks_idx_blocked_created ON {$social}.blocks (blocked_user_id, created_at)",

            "CREATE TABLE {$chat}.conversations (
                id BIGINT PRIMARY KEY CHECK (id > 0), type SMALLINT NOT NULL DEFAULT 1, last_message_id BIGINT,
                last_message_seq BIGINT NOT NULL DEFAULT 0 CHECK (last_message_seq >= 0), last_message_at TIMESTAMPTZ(3),
                private_key VARCHAR(64) COLLATE \"C\", creation_request_id VARCHAR(128) COLLATE \"C\",
                requesting_user_id BIGINT, target_mode SMALLINT NOT NULL DEFAULT 1, target_user_id BIGINT,
                target_persona_id BIGINT, target_scope_type VARCHAR(255) COLLATE \"C\", target_scope_id VARCHAR(255) COLLATE \"C\",
                target_anonymous_ref VARCHAR(128) COLLATE \"C\", created_at TIMESTAMPTZ(3) NOT NULL, updated_at TIMESTAMPTZ(3) NOT NULL,
                CONSTRAINT uniq_private_key UNIQUE (private_key)
            )",
            "CREATE INDEX conversations_idx_creation_request ON {$chat}.conversations (requesting_user_id, creation_request_id)",
            "CREATE INDEX conversations_idx_last_message_at ON {$chat}.conversations (last_message_at)",
            "CREATE TABLE {$chat}.conversation_members (
                id BIGINT PRIMARY KEY CHECK (id > 0), conversation_id BIGINT NOT NULL CHECK (conversation_id > 0),
                user_id BIGINT NOT NULL CHECK (user_id > 0), persona_id BIGINT NOT NULL CHECK (persona_id > 0),
                identity_mode SMALLINT NOT NULL DEFAULT 0, display_name_snapshot VARCHAR(64), avatar_key_snapshot VARCHAR(255),
                anonymous_ref VARCHAR(128) COLLATE \"C\", scope_type VARCHAR(255) COLLATE \"C\", scope_id VARCHAR(255) COLLATE \"C\",
                last_read_seq BIGINT NOT NULL DEFAULT 0 CHECK (last_read_seq >= 0), joined_at TIMESTAMPTZ(3) NOT NULL,
                left_at TIMESTAMPTZ(3), mute_until TIMESTAMPTZ(3), CONSTRAINT uniq_conv_user UNIQUE (conversation_id, user_id)
            )",
            "CREATE INDEX conversation_members_idx_user_conv ON {$chat}.conversation_members (user_id, conversation_id)",
        ];

        foreach ($statements as $sql) {
            $this->execute($sql);
        }
        foreach ([$auth, $user, $content, $social, $chat] as $schema) {
            $this->execute($this->outboxSql($schema));
            $this->execute("CREATE INDEX outbox_events_idx_status_available ON {$schema}.outbox_events (status, available_at, created_at, id)");
            $this->execute("CREATE INDEX outbox_events_idx_aggregate_order ON {$schema}.outbox_events (aggregate_type, aggregate_id, id, status)");
            $this->execute("CREATE INDEX outbox_events_idx_event_key_order ON {$schema}.outbox_events (event_key, id, status)");
            $this->execute("CREATE INDEX outbox_events_idx_status_lease ON {$schema}.outbox_events (status, lease_until)");
        }
        $this->execute("GRANT SELECT, UPDATE ON {$user}.outbox_events TO user_outbox_relay");
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Dropping the PostgreSQL baseline can destroy service data.');
    }

    private function outboxSql(string $schema): string
    {
        return "CREATE TABLE {$schema}.outbox_events (
            id BIGINT PRIMARY KEY CHECK (id > 0), event_id VARCHAR(36) COLLATE \"C\" NOT NULL,
            aggregate_type VARCHAR(64) COLLATE \"C\" NOT NULL, aggregate_id VARCHAR(64) COLLATE \"C\" NOT NULL,
            event_key VARCHAR(64) COLLATE \"C\" NOT NULL, trace_id VARCHAR(64) COLLATE \"C\" NOT NULL,
            event_type VARCHAR(128) COLLATE \"C\" NOT NULL, event_version INTEGER NOT NULL DEFAULT 1,
            payload JSONB NOT NULL, status SMALLINT NOT NULL DEFAULT 0 CHECK (status IN (0, 1, 2)),
            available_at TIMESTAMPTZ(3) NOT NULL, claimed_at TIMESTAMPTZ(3), lease_until TIMESTAMPTZ(3),
            claim_token VARCHAR(64) COLLATE \"C\", published_at TIMESTAMPTZ(3), created_at TIMESTAMPTZ(3) NOT NULL,
            attempt_count INTEGER NOT NULL DEFAULT 0 CHECK (attempt_count >= 0), CONSTRAINT uniq_event_id UNIQUE (event_id)
        )";
    }

    private function schemaPrefix(): string
    {
        $prefix = $this->getAdapter()->getOption('schema_prefix') ?? '';
        if (!is_string($prefix) || preg_match('/\A[a-zA-Z0-9_]*\z/', $prefix) !== 1) {
            throw new RuntimeException('schema_prefix must contain only ASCII letters, digits, and underscores.');
        }
        return $prefix;
    }

    private function id(string $identifier): string
    {
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,62}\z/', $identifier) !== 1) {
            throw new RuntimeException('Invalid PostgreSQL schema identifier');
        }
        return '"' . $identifier . '"';
    }
}
