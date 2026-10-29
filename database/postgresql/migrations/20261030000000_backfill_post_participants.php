<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** Root authors retain their original mode; other users retain their earliest comment's mode. */
final class BackfillPostParticipants extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('LOCK TABLE content_db.posts, content_db.comments, content_db.post_participants IN SHARE ROW EXCLUSIVE MODE');
        $this->execute(<<<'SQL'
WITH history AS (
    SELECT id, id AS post_id, author_user_id AS user_id, persona_id,
           identity_mode, author_display_name AS display_name, created_at, 0 AS priority FROM content_db.posts
    UNION ALL
    SELECT id, post_id, author_user_id, persona_id,
           identity_mode, author_display_name, created_at, 1 AS priority FROM content_db.comments
), first_speech AS (
    SELECT DISTINCT ON (post_id, user_id) * FROM history WHERE user_id > 0
    ORDER BY post_id, user_id, priority, created_at, id
)
INSERT INTO content_db.post_participants
    (id, post_id, user_id, persona_id, identity_mode, display_name, created_at, updated_at)
SELECT id, post_id, user_id, persona_id, identity_mode,
       CASE WHEN identity_mode = 2 THEN COALESCE(NULLIF(TRIM(display_name), ''), '匿名用户') ELSE NULL END,
       created_at, created_at
FROM first_speech WHERE identity_mode IN (1, 2) AND persona_id > 0
ON CONFLICT (post_id, user_id) DO NOTHING
SQL);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Historical identity locks must not be removed.');
    }
}
