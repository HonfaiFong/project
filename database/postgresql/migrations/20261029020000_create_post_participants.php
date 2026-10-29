<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * Per-post identity lock plus the board/home/own listing indexes.
 *
 * post_participants fixes the identity (and alias display name) an account
 * uses inside one post; later comments reuse it instead of re-resolving.
 * The partial indexes back the three listing access paths: boards by
 * (identity_mode, published_at, id), the MAIN persona home by
 * (persona_id, published_at, id) and the own management view by
 * (author_user_id, created_at, id).
 */
final class CreatePostParticipants extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('CREATE TABLE content_db.post_participants (
            id BIGINT PRIMARY KEY CHECK (id > 0), post_id BIGINT NOT NULL CHECK (post_id > 0),
            user_id BIGINT NOT NULL CHECK (user_id > 0), persona_id BIGINT NOT NULL CHECK (persona_id > 0),
            identity_mode SMALLINT NOT NULL CHECK (identity_mode IN (1, 2)),
            display_name VARCHAR(64), created_at TIMESTAMPTZ(3) NOT NULL, updated_at TIMESTAMPTZ(3) NOT NULL,
            CONSTRAINT uniq_post_participant UNIQUE (post_id, user_id)
        )');
        $this->execute('CREATE INDEX post_participants_idx_post ON content_db.post_participants (post_id)');

        $this->execute('CREATE INDEX posts_idx_board_main ON content_db.posts (published_at, id)
            WHERE identity_mode = 1 AND status = 1 AND visibility = 1 AND moderation_status = 1 AND deleted_at IS NULL AND published_at IS NOT NULL');
        $this->execute('CREATE INDEX posts_idx_board_alias ON content_db.posts (published_at, id)
            WHERE identity_mode = 2 AND status = 1 AND visibility = 1 AND moderation_status = 1 AND deleted_at IS NULL AND published_at IS NOT NULL');
        $this->execute('CREATE INDEX posts_idx_persona_home ON content_db.posts (persona_id, published_at, id)
            WHERE identity_mode = 1 AND status = 1 AND visibility = 1 AND moderation_status = 1 AND deleted_at IS NULL AND published_at IS NOT NULL');
        $this->execute('CREATE INDEX posts_idx_own_created ON content_db.posts (author_user_id, created_at, id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Dropping post_participants would discard per-post identity locks.');
    }
}
