<?php

declare(strict_types=1);

namespace Services\ContentService\Application;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\Common\V1\IdentityMode as ProtoIdentityMode;
use Contracts\Gen\Common\V1\PaginationRequest;
use Contracts\Gen\Common\V1\PaginationResponse;
use Contracts\Gen\Common\V1\PublicAuthor;
use Contracts\Gen\Content\V1\BoardScope;
use Contracts\Gen\Content\V1\MediaItem;
use Contracts\Gen\Content\V1\PublicComment;
use Contracts\Gen\Content\V1\PublicPost;
use Packages\GrpcCommon\BusinessActor;
use Packages\Database\PostgresErrorClassifier;
use Packages\Identity\AnonymousAuthorProjector;
use Packages\Identity\AnonymousAuthorSnapshotFactory;
use Packages\Identity\PersonaMode;
use Packages\Identity\PersonaOperation;
use Packages\Identity\UserIdentity;
use Packages\Identity\UserIdentityDirectory;
use Packages\IdGenerator\Snowflake;
use Packages\Outbox\EventOutboxInterface;
use Packages\Tracing\TraceContext;
use PDO;
use PDOException;
use RuntimeException;
use Services\ContentService\Domain\Social\BlockStatusResolver;
use Services\ContentService\Domain\Social\BatchBlockStatusResolver;
use Throwable;

/** PDO-backed content business boundary. */
final class ContentApplicationService
{
    private const ALIAS_DISPLAY_NAME_MAX = 20;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Snowflake $ids,
        private readonly UserIdentityDirectory $identities,
        private readonly BlockStatusResolver $blocks,
        private readonly AnonymousAuthorSnapshotFactory $snapshots,
        private readonly AnonymousAuthorProjector $projector,
        private readonly ?EventOutboxInterface $outbox = null,
    ) {
    }

    public function createPost(
        BusinessActor $actor,
        int $identityMode,
        string $requestedPersonaId,
        int $type,
        string $content,
        int $visibility,
        string $emotion,
        array $media,
        string $requestId,
        string $aliasDisplayName = '',
    ): PublicPost {
        $identity = $this->resolveIdentity($actor, $identityMode, PersonaOperation::CREATE_POST);
        $this->assertRequestedPersona($requestedPersonaId, $identity);
        $content = $this->requiredText($content, 65535, 'content');
        $requestKey = $this->requestId($requestId);
        if ($visibility < 1 || $visibility > 3) {
            throw new RuntimeException('visibility is invalid', ErrorCode::INVALID_ARGUMENT->value);
        }

        if (filled($requestKey)) {
            $existing = $this->findByRequest('posts', $actor->userId, $requestKey);
            if (!empty($existing)) {
                if (!$this->postRequestMatches($identity, $existing, $type, $content, $visibility, $this->nullableText($emotion, 32), $media, $aliasDisplayName)) {
                    throw new RuntimeException('request_id was already used with different parameters', ErrorCode::IDEMPOTENCY_CONFLICT->value);
                }

                return $this->getPost($actor, (string)$existing['id']);
            }
        }

        $postId = $this->ids->nextId();
        $aliasName = $this->aliasDisplayName($aliasDisplayName);
        $snapshot = $identity->mode === PersonaMode::MAIN
            ? $this->snapshots->forMain($identity->personaId, $identity->displayName, $identity->avatarKey)
            : $this->snapshots->forAlias(
                $identity->personaId,
                'post',
                $postId,
                $aliasName,
            );
        $now = $this->now();

        try {
            $this->transaction(function () use ($actor, $postId, $identity, $snapshot, $requestKey, $media, $now, $type, $content, $visibility, $emotion, $aliasName): void {
                $statement = $this->pdo->prepare(
                    'INSERT INTO "posts" '
                    . '("id", "author_user_id", "request_id", "persona_id", "identity_mode", "author_display_name", '
                    . '"author_avatar_key", "author_anonymous_ref", "author_scope_type", "author_scope_id", '
                    . '"type", "content", "visibility", "emotion", "status", "moderation_status", '
                    . '"published_at", "created_at", "updated_at") '
                    . 'VALUES (:id, :user_id, :request_id, :persona_id, :identity_mode, :display_name, :avatar_key, '
                    . ':anonymous_ref, :scope_type, :scope_id, :type, :content, :visibility, :emotion, '
                    . '1, 1, :published_at, :created_at, :updated_at)',
                );
                $statement->execute([
                    'id' => $postId,
                    'user_id' => $actor->userId,
                    'persona_id' => $identity->personaId,
                    'request_id' => $requestKey,
                    'identity_mode' => $identity->mode->value,
                    'display_name' => $snapshot->displayName,
                    'avatar_key' => $snapshot->avatarKey,
                    'anonymous_ref' => $snapshot->anonymousRef,
                    'scope_type' => $snapshot->scopeType,
                    'scope_id' => $snapshot->scopeId,
                    'type' => $type,
                    'content' => $content,
                    'visibility' => $visibility,
                    'emotion' => $this->nullableText($emotion, 32),
                    'published_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->insertMedia($postId, $media, $now);
                // The author is the first locked participant of the post; the
                // row is what later comments resolve their identity against.
                $this->settleParticipant($postId, $actor, $identity, $aliasName, $now);
                $this->recordEvent('post', $postId, 'post.created.v1', $requestKey ?? '', [
                    'post_id' => $postId,
                    'user_id' => $actor->userId,
                    'persona_id' => $identity->personaId,
                    'identity_mode' => $identity->mode->value,
                ]);
            });
        } catch (PDOException $e) {
            if (filled($requestKey) && $this->isUniqueViolation($e)) {
                $existing = $this->findByRequest('posts', $actor->userId, $requestKey);
                if (empty($existing)) {
                    throw $e;
                }
                if (!$this->postRequestMatches($identity, $existing, $type, $content, $visibility, $this->nullableText($emotion, 32), $media, $aliasDisplayName)) {
                    throw new RuntimeException('request_id was already used with different parameters', ErrorCode::IDEMPOTENCY_CONFLICT->value, $e);
                }
                return $this->getPost($actor, (string)$existing['id']);
            }
            throw $e;
        }

        return $this->getPost($actor, $postId);
    }

    public function getPost(?BusinessActor $actor, string $postId): PublicPost
    {
        $row = $this->findPost($postId);
        if (empty($row)) {
            throw new RuntimeException(ErrorCode::CONTENT_POST_NOT_FOUND->message(), ErrorCode::CONTENT_POST_NOT_FOUND->value);
        }
        $this->assertPostReadable($row, $actor);

        return $this->projectPost($row);
    }

    /**
     * @param list<string> $postIds
     * @return list<PublicPost>
     */
    public function batchGetPosts(?BusinessActor $actor, array $postIds): array
    {
        $posts = [];
        foreach (array_values(array_unique($postIds)) as $postId) {
            try {
                $posts[] = $this->getPost($actor, $postId);
            } catch (RuntimeException $e) {
                if ($e->getCode() !== ErrorCode::CONTENT_POST_NOT_FOUND->value) {
                    throw $e;
                }
            }
        }

        return $posts;
    }

    public function deletePost(BusinessActor $actor, string $postId, string $requestId): void
    {
        $row = $this->findPost($postId);
        if (empty($row)) {
            throw new RuntimeException(ErrorCode::CONTENT_POST_NOT_FOUND->message(), ErrorCode::CONTENT_POST_NOT_FOUND->value);
        }
        $this->assertOwner($row, $actor);
        if ((int)$row['status'] === 0 || $row['deleted_at'] !== null) {
            return;
        }

        $now = $this->now();
        $this->transaction(function () use ($actor, $postId, $requestId, $now): void {
            $statement = $this->pdo->prepare(
                'UPDATE "posts" SET "status" = 0, "deleted_at" = :deleted_at, "updated_at" = :updated_at '
                . 'WHERE "id" = :id AND "author_user_id" = :user_id AND "deleted_at" IS NULL',
            );
            $statement->execute(['deleted_at' => $now, 'updated_at' => $now, 'id' => $postId, 'user_id' => $actor->userId]);
            if ($statement->rowCount() === 0) {
                // A concurrent delete already won the row; its transaction owns the event.
                return;
            }
            $this->recordEvent('post', $postId, 'post.deleted.v1', $requestId, ['post_id' => $postId, 'user_id' => $actor->userId]);
        });
    }

    public function updatePostStatus(string $postId, int $status, int $moderationStatus, string $requestId): void
    {
        if ($status < 0 || $status > 3 || $moderationStatus < 0 || $moderationStatus > 3) {
            throw new RuntimeException('content status is invalid', ErrorCode::INVALID_ARGUMENT->value);
        }
        $this->transaction(function () use ($postId, $status, $moderationStatus, $requestId): void {
            $statement = $this->pdo->prepare(
                'UPDATE "posts" SET "status" = :status, "moderation_status" = :moderation_status, '
                . '"updated_at" = :updated_at WHERE "id" = :id AND "deleted_at" IS NULL '
                . 'AND ("status" <> :current_status OR "moderation_status" <> :current_moderation_status)',
            );
            $statement->execute([
                'status' => $status,
                'moderation_status' => $moderationStatus,
                'updated_at' => $this->now(),
                'id' => $postId,
                'current_status' => $status,
                'current_moderation_status' => $moderationStatus,
            ]);
            $affected = $statement->rowCount();
            if ($affected === 0) {
                if (empty($this->findPost($postId))) {
                    throw new RuntimeException(ErrorCode::CONTENT_POST_NOT_FOUND->message(), ErrorCode::CONTENT_POST_NOT_FOUND->value);
                }

                // The row already carries the requested status; a no-op replay must not duplicate the event.
                return;
            }
            $this->recordEvent('post', $postId, 'post.status.updated.v1', $requestId, [
                'post_id' => $postId,
                'status' => $status,
                'moderation_status' => $moderationStatus,
            ]);
        });
    }

    public function createComment(
        BusinessActor $actor,
        int $identityMode,
        string $requestedPersonaId,
        string $postId,
        string $parentId,
        string $replyToCommentId,
        string $content,
        string $requestId,
        string $aliasDisplayName = '',
    ): PublicComment {
        $post = $this->findPost($postId);
        if (empty($post)) {
            throw new RuntimeException(ErrorCode::CONTENT_POST_NOT_FOUND->message(), ErrorCode::CONTENT_POST_NOT_FOUND->value);
        }
        $this->assertPostReadable($post, $actor);
        $identity = $this->resolveIdentity($actor, $identityMode, PersonaOperation::CREATE_COMMENT);
        $this->assertRequestedPersona($requestedPersonaId, $identity);
        $content = $this->requiredText($content, 2000, 'content');
        $requestKey = $this->requestId($requestId);
        // The per-post identity lock is resolved before the idempotency replay
        // so a retry compares against the locked alias nickname, not the body.
        $participant = $this->findParticipant($postId, $actor->userId);
        if ($participant !== null) {
            $this->assertParticipantMatches($participant, $identity);
        }
        $aliasName = $identity->mode === PersonaMode::ALIAS
            ? $this->lockedAliasName($participant, $aliasDisplayName)
            : '';
        if (filled($requestKey)) {
            $existing = $this->findByRequest('comments', $actor->userId, $requestKey);
            if (!empty($existing)) {
                if (!$this->commentRequestMatches($identity, $existing, $postId, $this->nullableId($parentId), $this->nullableId($replyToCommentId), $content, $aliasName)) {
                    throw new RuntimeException('request_id was already used with different parameters', ErrorCode::IDEMPOTENCY_CONFLICT->value);
                }

                return $this->getComment($actor, (string)$existing['id']);
            }
        }
        $parentId = $this->nullableId($parentId);
        $replyToCommentId = $this->nullableId($replyToCommentId);
        if ($parentId !== null) {
            $this->assertCommentBelongsToPost($parentId, $postId);
        }
        if ($replyToCommentId !== null) {
            $this->assertCommentBelongsToPost($replyToCommentId, $postId);
        }

        $commentId = $this->ids->nextId();
        $now = $this->now();

        try {
            $this->transaction(function () use ($actor, $commentId, $identity, $requestKey, $postId, $parentId, $replyToCommentId, $content, $now, $participant, $aliasName): void {
                $finalAliasName = $aliasName;
                if ($participant === null) {
                    [, $finalAliasName] = $this->settleParticipant($postId, $actor, $identity, $aliasName, $now);
                }
                $snapshot = $identity->mode === PersonaMode::MAIN
                    ? $this->snapshots->forMain($identity->personaId, $identity->displayName, $identity->avatarKey)
                    : $this->snapshots->forAlias(
                        $identity->personaId,
                        'post',
                        $postId,
                        $finalAliasName,
                    );
                $statement = $this->pdo->prepare(
                    'INSERT INTO "comments" '
                    . '("id", "post_id", "author_user_id", "request_id", "persona_id", "identity_mode", "author_display_name", '
                    . '"author_avatar_key", "author_anonymous_ref", "author_scope_type", "author_scope_id", '
                    . '"parent_id", "reply_to_comment_id", "content", "status", "created_at", "updated_at") '
                    . 'VALUES (:id, :post_id, :user_id, :request_id, :persona_id, :identity_mode, :display_name, :avatar_key, '
                    . ':anonymous_ref, :scope_type, :scope_id, :parent_id, :reply_to_comment_id, :content, 1, :created_at, :updated_at)',
                );
                $statement->execute([
                    'id' => $commentId,
                    'post_id' => $postId,
                    'user_id' => $actor->userId,
                    'persona_id' => $identity->personaId,
                    'request_id' => $requestKey,
                    'identity_mode' => $identity->mode->value,
                    'display_name' => $snapshot->displayName,
                    'avatar_key' => $snapshot->avatarKey,
                    'anonymous_ref' => $snapshot->anonymousRef,
                    'scope_type' => $snapshot->scopeType,
                    'scope_id' => $snapshot->scopeId,
                    'parent_id' => $parentId,
                    'reply_to_comment_id' => $replyToCommentId,
                    'content' => $content,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $updatePost = $this->pdo->prepare('UPDATE "posts" SET "comment_count" = "comment_count" + 1 WHERE "id" = :id');
                $updatePost->execute(['id' => $postId]);
                $this->recordEvent('comment', $commentId, 'comment.created.v1', $requestKey ?? '', [
                    'comment_id' => $commentId,
                    'post_id' => $postId,
                    'user_id' => $actor->userId,
                    'persona_id' => $identity->personaId,
                    'identity_mode' => $identity->mode->value,
                ]);
            });
        } catch (PDOException $e) {
            if (filled($requestKey) && $this->isUniqueViolation($e)) {
                $existing = $this->findByRequest('comments', $actor->userId, $requestKey);
                if (empty($existing)) {
                    throw $e;
                }
                if (!$this->commentRequestMatches($identity, $existing, $postId, $parentId, $replyToCommentId, $content, $aliasName)) {
                    throw new RuntimeException('request_id was already used with different parameters', ErrorCode::IDEMPOTENCY_CONFLICT->value, $e);
                }
                return $this->getComment($actor, (string)$existing['id']);
            }
            throw $e;
        }

        return $this->getComment($actor, $commentId);
    }

    /** @return array{comments:list<PublicComment>, pagination:PaginationResponse} */
    public function listComments(?BusinessActor $actor, string $postId, string $parentId, ?PaginationRequest $pagination): array
    {
        $post = $this->findPost($postId);
        if (empty($post)) {
            throw new RuntimeException(ErrorCode::CONTENT_POST_NOT_FOUND->message(), ErrorCode::CONTENT_POST_NOT_FOUND->value);
        }
        $this->assertPostReadable($post, $actor);
        $parentId = $this->nullableId($parentId);
        $limit = min(max($pagination?->getPageSize() ?? 20, 1), 100);
        $cursor = $pagination?->getCursor() ?? '';
        $rows = [];
        $rawCursor = $cursor;
        $hasMore = false;
        /** @var array<string, mixed> $chainCache */
        $chainCache = [];
        /** @var array<string, mixed> $blockedAuthors */
        $blockedAuthors = [];
        while (true) {
            $sql = 'SELECT * FROM "comments" WHERE "post_id" = :post_id AND "status" = 1 AND "deleted_at" IS NULL';
            $params = ['post_id' => $postId];
            if ($parentId === null) {
                $sql .= ' AND "parent_id" IS NULL';
            } else {
                $sql .= ' AND "parent_id" = :parent_id';
                $params['parent_id'] = $parentId;
            }
            if (filled($rawCursor)) {
                $sql .= ' AND "id" < :cursor';
                $params['cursor'] = $rawCursor;
            }
            $sql .= ' ORDER BY "id" DESC LIMIT ' . ($limit + 1);
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            $page = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (empty($page)) {
                break;
            }
            $this->loadChainContext($page, $chainCache);
            foreach ($page as $row) {
                $rawCursor = (string)$row['id'];
                if (!$this->isCommentChainReadable($row, $chainCache)) {
                    continue;
                }
                if ($actor !== null && $this->cachedIsBlocked($actor->userId, (string)($row['author_user_id'] ?? ''), $blockedAuthors)) {
                    continue;
                }
                $rows[] = $row;
                if (count($rows) > $limit) {
                    $hasMore = true;
                    break 2;
                }
            }
            if (count($page) <= $limit) {
                break;
            }
        }
        $rows = array_slice($rows, 0, $limit);

        return [
            'comments' => array_map(fn (array $row): PublicComment => $this->projectComment($row), $rows),
            'pagination' => new PaginationResponse([
                'next_cursor' => $hasMore && !empty($rows) ? (string)$rows[array_key_last($rows)]['id'] : '',
                'has_more' => $hasMore,
                'total' => $this->countComments($postId, $parentId),
            ]),
        ];
    }

    /**
     * Resolves one listing scope into a public post page.
     *
     * Boards and the persona home only ever expose public, approved MAIN or
     * ALIAS posts; the own view exposes everything the authenticated account
     * still owns, hidden rows included, so the management UI can render
     * states. Block filtering belongs here, not to the caller.
     *
     * @return array{posts:list<PublicPost>, pagination:PaginationResponse}
     */
    public function listPosts(
        ?BusinessActor $actor,
        int $scope,
        string $personaId,
        int $identityFilter,
        ?PaginationRequest $pagination,
    ): array {
        $limit = min(max($pagination?->getPageSize() ?? 20, 1), 100);
        [$cursorTime, $cursorId] = $this->decodeListCursor(trim($pagination?->getCursor() ?? ''));

        $where = [];
        $params = [];
        $orderColumn = 'published_at';
        $filterBlocks = false;
        switch ($scope) {
            case BoardScope::BOARD_SCOPE_MAIN:
            case BoardScope::BOARD_SCOPE_ALIAS:
                $where[] = '"identity_mode" = :identity_mode';
                $params['identity_mode'] = $scope;
                $where[] = $this->publicPostFilter();
                $filterBlocks = true;
                break;
            case BoardScope::BOARD_SCOPE_PERSONA_HOME:
                if (!$this->isPositiveId($personaId)) {
                    throw new RuntimeException('persona_id is required', ErrorCode::INVALID_ARGUMENT->value);
                }
                $where[] = '"persona_id" = :persona_id';
                $params['persona_id'] = $personaId;
                $where[] = '"identity_mode" = 1';
                $where[] = $this->publicPostFilter();
                $filterBlocks = true;
                break;
            case BoardScope::BOARD_SCOPE_OWN:
                if ($actor === null) {
                    throw new RuntimeException('authentication is required', ErrorCode::AUTH_UNAUTHORIZED->value);
                }
                $where[] = '"author_user_id" = :user_id';
                $params['user_id'] = $actor->userId;
                $where[] = '"deleted_at" IS NULL';
                if ($identityFilter !== ProtoIdentityMode::IDENTITY_MODE_UNSPECIFIED) {
                    if (!in_array($identityFilter, [ProtoIdentityMode::IDENTITY_MODE_MAIN, ProtoIdentityMode::IDENTITY_MODE_ALIAS], true)) {
                        throw new RuntimeException('identity_filter is invalid', ErrorCode::INVALID_ARGUMENT->value);
                    }
                    $where[] = '"identity_mode" = :identity_filter';
                    $params['identity_filter'] = $identityFilter;
                }
                $orderColumn = 'created_at';
                break;
            default:
                throw new RuntimeException('scope is invalid', ErrorCode::INVALID_ARGUMENT->value);
        }

        // Bound both database work and authorization work, even for an all-blocked tail.
        $countParams = $params;
        $scanLimit = 100;
        $sql = 'SELECT * FROM "posts" WHERE ' . implode(' AND ', $where);
        if ($cursorTime !== null) {
            $sql .= ' AND ("' . $orderColumn . '" < :cursor_time OR ("' . $orderColumn . '" = :cursor_time AND "id" < :cursor_id))';
            $params['cursor_time'] = $cursorTime;
            $params['cursor_id'] = $cursorId;
        }
        $statement = $this->pdo->prepare($sql . ' ORDER BY "' . $orderColumn . '" DESC, "id" DESC LIMIT ' . ($scanLimit + 1));
        $statement->execute($params);
        $page = $statement->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($page) > $scanLimit;
        $page = array_slice($page, 0, $scanLimit);
        $blocked = [];
        if ($filterBlocks && $actor !== null) {
            $authors = array_values(array_unique(array_map(static fn (array $row): string => (string)$row['author_user_id'], $page)));
            $authors = array_values(array_filter($authors, static fn (string $id): bool => $id !== $actor->userId));
            if ($authors !== []) {
                if ($this->blocks instanceof BatchBlockStatusResolver) {
                    $blocked = array_fill_keys($this->blocks->blockedUserIds($actor->userId, $authors), true);
                } else {
                    foreach ($authors as $author) {
                        if ($this->blocks->isBlockedEitherWay($actor->userId, $author)) {
                            $blocked[$author] = true;
                        }
                    }
                }
            }
        }
        $rows = [];
        $lastScanned = null;
        foreach ($page as $index => $row) {
            $lastScanned = $row;
            if (!isset($blocked[(string)$row['author_user_id']])) {
                $rows[] = $row;
            }
            if (count($rows) === $limit) {
                $hasMore = $hasMore || $index < count($page) - 1;
                break;
            }
        }
        return [
            'posts' => array_map(fn (array $row): PublicPost => $this->projectPost($row), $rows),
            'pagination' => new PaginationResponse([
                'next_cursor' => $hasMore && $lastScanned !== null
                    ? $this->encodeListCursor((string)$lastScanned[$orderColumn], (string)$lastScanned['id']) : '',
                'has_more' => $hasMore,
                // Public totals would count blocked authors and leak invisible content.
                'total' => $scope === BoardScope::BOARD_SCOPE_OWN ? $this->countListPosts($where, $countParams) : 0,
            ]),
        ];
    }

    /** Rows any other account may see on boards, the persona home or detail lookups. */
    private function publicPostFilter(): string
    {
        return '"status" = 1 AND "visibility" = 1 AND "moderation_status" = 1'
            . ' AND "deleted_at" IS NULL AND "published_at" IS NOT NULL';
    }

    /** @param list<string> $where @param array<string, mixed> $params */
    private function countListPosts(array $where, array $params): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM "posts" WHERE ' . implode(' AND ', $where));
        $statement->execute($params);

        return (int)$statement->fetchColumn();
    }

    private function encodeListCursor(string $time, string $id): string
    {
        return rtrim(strtr(base64_encode($time . '|' . $id), '+/', '-_'), '=');
    }

    /** @return array{?string, ?string} */
    private function decodeListCursor(string $cursor): array
    {
        if ($cursor === '') {
            return [null, null];
        }
        if (strlen($cursor) > 128) {
            throw new RuntimeException('cursor is invalid', ErrorCode::INVALID_ARGUMENT->value);
        }
        $padded = strtr($cursor, '-_', '+/') . str_repeat('=', (4 - strlen($cursor) % 4) % 4);
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new RuntimeException('cursor is invalid', ErrorCode::INVALID_ARGUMENT->value);
        }
        $parts = explode('|', $decoded);
        if (count($parts) !== 2 || !$this->validCursorTime($parts[0]) || !$this->isPositiveId($parts[1]) || strlen($parts[1]) > 19 || (strlen($parts[1]) === 19 && strcmp($parts[1], '9223372036854775807') > 0)) {
            throw new RuntimeException('cursor is invalid', ErrorCode::INVALID_ARGUMENT->value);
        }

        return [$parts[0], $parts[1]];
    }

    private function validCursorTime(string $time): bool
    {
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}(?::?[0-9]{2})?)?\z/', $time) !== 1) {
            return false;
        }
        if (substr($time, 0, 4) === '0000') {
            return false;
        }
        if (preg_match('/[+-]([0-9]{2})(?::?([0-9]{2}))?\z/', $time, $offset) === 1
            && ((int)$offset[1] > 14 || (int)($offset[2] ?? 0) > 59 || ((int)$offset[1] === 14 && (int)($offset[2] ?? 0) !== 0))) {
            return false;
        }
        try {
            new \DateTimeImmutable($time);
            $errors = \DateTimeImmutable::getLastErrors();
            return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
        } catch (\Exception) {
            return false;
        }
    }

    /** @return array<string, mixed>|null */
    private function findParticipant(string $postId, string $userId): ?array
    {
        if (!$this->isPositiveId($postId) || blank($userId)) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT * FROM "post_participants" WHERE "post_id" = :post_id AND "user_id" = :user_id LIMIT 1',
        );
        $statement->execute(['post_id' => $postId, 'user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
        // Also protect historical records during upgrades; never infer MAIN from current profiles.
        $statement = $this->pdo->prepare(
            'SELECT persona_id, identity_mode, display_name FROM ('
            . 'SELECT id, persona_id, identity_mode, author_display_name AS display_name, created_at, 0 AS priority FROM posts WHERE id = ? AND author_user_id = ? '
            . 'UNION ALL SELECT id, persona_id, identity_mode, author_display_name AS display_name, created_at, 1 AS priority FROM comments WHERE post_id = ? AND author_user_id = ?'
            . ') AS history ORDER BY priority, created_at, id LIMIT 1',
        );
        $statement->execute([$postId, $userId, $postId, $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && !in_array((int)$row['identity_mode'], [1, 2], true)) {
            throw new RuntimeException('historical identity is unavailable', ErrorCode::PERSONA_FORBIDDEN->value);
        }
        return is_array($row) ? $row : null;
    }

    /**
     * Locks the account's speaking identity for one post and returns the
     * effective alias nickname. Insert-if-absent plus re-read keeps a
     * concurrent first speaker race-free: the loser of the unique key adopts
     * the winner's identity and nickname instead of overriding it.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function settleParticipant(
        string $postId,
        BusinessActor $actor,
        UserIdentity $identity,
        string $requestedAliasName,
        string $now,
    ): array {
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $statement = $this->pdo->prepare(
            ($driver === 'mysql' || $driver === 'mariadb' ? 'INSERT IGNORE INTO' : 'INSERT INTO')
            . ' "post_participants" ("id", "post_id", "user_id", "persona_id", "identity_mode", '
            . '"display_name", "created_at", "updated_at") VALUES (:id, :post_id, :user_id, :persona_id, '
            . ':identity_mode, :display_name, :created_at, :updated_at)'
            . ($driver === 'mysql' || $driver === 'mariadb' ? '' : ' ON CONFLICT DO NOTHING'),
        );
        $statement->execute([
            'id' => $this->ids->nextId(),
            'post_id' => $postId,
            'user_id' => $actor->userId,
            'persona_id' => $identity->personaId,
            'identity_mode' => $identity->mode->value,
            'display_name' => $identity->mode === PersonaMode::ALIAS ? $requestedAliasName : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $participant = $this->findParticipant($postId, $actor->userId);
        if ($participant === null) {
            throw new RuntimeException('post participant is unavailable', ErrorCode::INTERNAL_SERVER_ERROR->value);
        }
        $this->assertParticipantMatches($participant, $identity);
        $name = $identity->mode === PersonaMode::ALIAS
            ? $this->lockedAliasName($participant, $requestedAliasName)
            : '';

        return [$participant, $name];
    }

    /**
     * @param array<string, mixed>|null $participant
     */
    private function assertParticipantMatches(?array $participant, UserIdentity $identity): void
    {
        if ($participant !== null && (int)$participant['identity_mode'] !== $identity->mode->value) {
            throw new RuntimeException('identity is locked for this post', ErrorCode::PERSONA_FORBIDDEN->value);
        }
    }

    /**
     * The locked nickname wins once the participant row exists; an empty
     * stored value falls back to the request (or the system default).
     *
     * @param array<string, mixed>|null $participant
     */
    private function lockedAliasName(?array $participant, string $requested): string
    {
        $stored = trim((string)($participant['display_name'] ?? ''));
        if (filled($stored)) {
            return $stored;
        }

        return $this->aliasDisplayName($requested);
    }

    /**
     * Loads every referenced parent/reply row for a page with one query instead of one lookup per comment,
     * preserving the per-row visibility chain semantics.
     *
     * @param list<array<string, mixed>> $page
     * @param array<string, mixed> $cache comment rows (or null) for parent/reply visibility checks
     * @param-out array<string, mixed> $cache
     */
    private function loadChainContext(array $page, array &$cache): void
    {
        $ids = [];
        foreach ($page as $row) {
            foreach (['parent_id', 'reply_to_comment_id'] as $column) {
                $id = trim((string)($row[$column] ?? ''));
                if (!empty($id) && !array_key_exists($id, $cache)) {
                    $ids[$id] = $id;
                }
            }
        }
        if (empty($ids)) {
            return;
        }
        $list = array_values($ids);
        $placeholders = implode(', ', array_fill(0, count($list), '?'));
        $statement = $this->pdo->prepare('SELECT * FROM "comments" WHERE "id" IN (' . $placeholders . ')');
        $statement->execute($list);
        $fetched = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fetched[(string)$row['id']] = $row;
        }
        foreach ($list as $id) {
            $cache[$id] = $fetched[$id] ?? null;
        }
    }

    /**
     * @param array<string, bool> $cache
     * @param-out array<string, bool> $cache
     */
    private function cachedIsBlocked(string $actorUserId, string $targetUserId, array &$cache): bool
    {
        if (blank($targetUserId) || $targetUserId === $actorUserId) {
            return false;
        }
        if (array_key_exists($targetUserId, $cache)) {
            return $cache[$targetUserId];
        }
        $cache[$targetUserId] = $this->isBlocked($actorUserId, $targetUserId);
        return $cache[$targetUserId];
    }

    public function getComment(?BusinessActor $actor, string $commentId): PublicComment
    {
        $statement = $this->pdo->prepare('SELECT * FROM "comments" WHERE "id" = :id LIMIT 1');
        $statement->execute(['id' => $commentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int)$row['status'] !== 1 || $row['deleted_at'] !== null || !$this->isCommentChainReadable($row)) {
            throw new RuntimeException(ErrorCode::CONTENT_COMMENT_NOT_FOUND->message(), ErrorCode::CONTENT_COMMENT_NOT_FOUND->value);
        }
        $post = $this->findPost((string)$row['post_id']);
        if (empty($post)) {
            throw new RuntimeException(ErrorCode::CONTENT_POST_NOT_FOUND->message(), ErrorCode::CONTENT_POST_NOT_FOUND->value);
        }
        $this->assertPostReadable($post, $actor);
        if ($actor !== null && $this->isBlocked($actor->userId, (string)($row['author_user_id'] ?? ''))) {
            throw new RuntimeException(ErrorCode::SOCIAL_USER_BLOCKED->message(), ErrorCode::SOCIAL_USER_BLOCKED->value);
        }

        return $this->projectComment($row);
    }

    public function deleteComment(BusinessActor $actor, string $commentId, string $requestId): void
    {
        $statement = $this->pdo->prepare('SELECT * FROM "comments" WHERE "id" = :id LIMIT 1');
        $statement->execute(['id' => $commentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException(ErrorCode::CONTENT_COMMENT_NOT_FOUND->message(), ErrorCode::CONTENT_COMMENT_NOT_FOUND->value);
        }
        $this->assertOwner($row, $actor);
        if ((int)$row['status'] === 0 || $row['deleted_at'] !== null) {
            return;
        }
        $now = $this->now();
        $postId = (string)$row['post_id'];
        $this->transaction(function () use ($actor, $commentId, $postId, $requestId, $now): void {
            $update = $this->pdo->prepare(
                'UPDATE "comments" SET "status" = 0, "deleted_at" = :deleted_at, "updated_at" = :updated_at '
                . 'WHERE "id" = :id AND "author_user_id" = :user_id AND "deleted_at" IS NULL',
            );
            $update->execute(['deleted_at' => $now, 'updated_at' => $now, 'id' => $commentId, 'user_id' => $actor->userId]);
            if ($update->rowCount() === 0) {
                // A concurrent delete already won the row; its transaction owns the event and the count.
                return;
            }
            $this->decrementPostCommentCount($postId);
            $this->recordEvent('comment', $commentId, 'comment.deleted.v1', $requestId, ['comment_id' => $commentId, 'user_id' => $actor->userId]);
        });
    }

    /** Soft-deleted comments leave the projectable count, so the denormalized post counter follows one-for-one. */
    private function decrementPostCommentCount(string $postId): void
    {
        if (!$this->isPositiveId($postId)) {
            return;
        }
        $statement = $this->pdo->prepare(
            'UPDATE "posts" SET "comment_count" = "comment_count" - 1 '
            . 'WHERE "id" = :id AND "comment_count" > 0',
        );
        $statement->execute(['id' => $postId]);
    }

    /**
     * Owns the business and Outbox transaction on the same PDO connection; nested writes are unsupported.
     */
    private function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (Throwable) {
                // Preserve the operation failure if MySQL has already rolled back or disconnected.
            }
            throw $e;
        }
    }

    /**
     * The stored row is only a valid retry when the same business identity and parameters produced it;
     * switching identity mode or persona under one request_id is an explicit conflict.
     *
     * @param array<string, mixed> $existing
     */
    private function postRequestMatches(
        UserIdentity $identity,
        array $existing,
        int $type,
        string $content,
        int $visibility,
        ?string $emotion,
        array $media,
        string $aliasDisplayName,
    ): bool {
        return (string)$existing['persona_id'] === $identity->personaId
            && (int)$existing['identity_mode'] === $identity->mode->value
            && ($identity->mode !== PersonaMode::ALIAS || (string)$existing['author_display_name'] === $this->aliasDisplayName($aliasDisplayName))
            && (int)$existing['type'] === $type
            && (string)$existing['content'] === $content
            && (int)$existing['visibility'] === $visibility
            && (string)($existing['emotion'] ?? '') === (string)($emotion ?? '')
            && $this->postMediaMatches((string)$existing['id'], $media);
    }

    /** @param array<string, mixed> $existing */
    private function commentRequestMatches(
        UserIdentity $identity,
        array $existing,
        string $postId,
        ?string $parentId,
        ?string $replyToCommentId,
        string $content,
        string $effectiveAliasName,
    ): bool {
        return (string)$existing['persona_id'] === $identity->personaId
            && (int)$existing['identity_mode'] === $identity->mode->value
            && ($identity->mode !== PersonaMode::ALIAS || (string)$existing['author_display_name'] === $effectiveAliasName)
            && (string)$existing['post_id'] === $postId
            && (string)($existing['parent_id'] ?? '') === (string)($parentId ?? '')
            && (string)($existing['reply_to_comment_id'] ?? '') === (string)($replyToCommentId ?? '')
            && (string)$existing['content'] === $content;
    }

    /**
     * @param array<mixed> $expectedMedia
     */
    private function postMediaMatches(string $postId, array $expectedMedia): bool
    {
        $expected = [];
        foreach (array_values($expectedMedia) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $expected[] = [
                'type' => (int)($item['type'] ?? 1),
                'object_key' => trim((string)($item['object_key'] ?? '')),
                'mime_type' => trim((string)($item['mime_type'] ?? 'application/octet-stream')),
                'width' => (int)($item['width'] ?? 0),
                'height' => (int)($item['height'] ?? 0),
                'duration_ms' => (int)($item['duration_ms'] ?? 0),
                'sort_order' => (int)($item['sort_order'] ?? $index),
            ];
        }
        usort($expected, static fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);
        $statement = $this->pdo->prepare(
            'SELECT "type", "object_key", "mime_type", "width", "height", "duration_ms", "sort_order" '
            . 'FROM "post_media" WHERE "post_id" = :post_id ORDER BY "sort_order", "id"',
        );
        $statement->execute(['post_id' => $postId]);
        $stored = array_map(
            static fn (array $row): array => [
                'type' => (int)$row['type'],
                'object_key' => (string)$row['object_key'],
                'mime_type' => (string)$row['mime_type'],
                'width' => (int)($row['width'] ?? 0),
                'height' => (int)($row['height'] ?? 0),
                'duration_ms' => (int)($row['duration_ms'] ?? 0),
                'sort_order' => (int)($row['sort_order'] ?? 0),
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );

        return $stored === $expected;
    }

    /** @return array{user_id:string,persona_id:string,identity_mode:int,author:PublicAuthor} */
    public function resolveAnonymousTarget(BusinessActor $actor, string $scopeType, string $scopeId, string $anonymousRef): array
    {
        $scopeType = $this->requiredText($scopeType, 255, 'scope_type');
        $scopeId = $this->requiredText($scopeId, 255, 'scope_id');
        $anonymousRef = $this->requiredText($anonymousRef, 128, 'anonymous_ref');
        if ($scopeType !== 'post' || !$this->isPositiveId($scopeId)
            || preg_match('/\Aanon_[A-Za-z0-9_-]{1,120}\z/', $anonymousRef) !== 1
        ) {
            throw new RuntimeException(ErrorCode::CONTENT_COMMENT_NOT_FOUND->message(), ErrorCode::CONTENT_COMMENT_NOT_FOUND->value);
        }
        $where = $this->exactColumn('author_scope_type', 'scope_type')
            . ' AND ' . $this->exactColumn('author_scope_id', 'scope_id')
            . ' AND ' . $this->exactColumn('author_anonymous_ref', 'anonymous_ref')
            . ' AND "identity_mode" = 2 AND "author_user_id" IS NOT NULL'
            . ' AND "status" = 1 AND "deleted_at" IS NULL';
        foreach (['posts', 'comments'] as $table) {
            $scopeFilter = $table === 'posts' ? '"id" = :scope_id' : '"post_id" = :scope_id';
            $statement = $this->pdo->prepare(
                'SELECT * FROM "' . $table . '" WHERE ' . $scopeFilter . ' AND ' . $where . ' LIMIT 1',
            );
            $statement->execute([
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'anonymous_ref' => $anonymousRef,
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $root = $table === 'posts' ? $row : $this->findPost((string)$row['post_id']);
                if (!is_array($root)) {
                    continue;
                }
                if ($table === 'comments' && !$this->isCommentChainReadable($row)) {
                    continue;
                }
                $this->assertPostReadable($root, $actor);
                if ($this->isBlocked($actor->userId, (string)$row['author_user_id'])) {
                    throw new RuntimeException(ErrorCode::SOCIAL_USER_BLOCKED->message(), ErrorCode::SOCIAL_USER_BLOCKED->value);
                }
                return [
                    'user_id' => (string)$row['author_user_id'],
                    'persona_id' => (string)$row['persona_id'],
                    'identity_mode' => (int)$row['identity_mode'],
                    'author' => $this->projectAuthor(
                        $row,
                        $table === 'posts' ? 'post' : 'post',
                        $table === 'posts' ? (string)$row['id'] : (string)$row['post_id'],
                    ),
                ];
            }
        }

        throw new RuntimeException(ErrorCode::CONTENT_COMMENT_NOT_FOUND->message(), ErrorCode::CONTENT_COMMENT_NOT_FOUND->value);
    }

    /** @return array{allowed:bool,reason:string,resource_author_user_id:string,root_author_user_id:string} */
    public function canAccessResource(
        BusinessActor $actor,
        string $resourceType,
        string $resourceId,
        string $action,
        bool $checkBlocks = true,
    ): array
    {
        $row = match ($resourceType) {
            'post' => $this->findPost($resourceId),
            'comment' => $this->findCommentRow($resourceId),
            default => null,
        };
        if (empty($row) || ($row['deleted_at'] ?? null) !== null || (int)($row['status'] ?? 0) !== 1) {
            return $this->resourceDenied('resource_not_found');
        }
        $root = $resourceType === 'post' ? $row : $this->findPost((string)$row['post_id']);
        if ($resourceType === 'comment') {
            if (!$this->isCommentChainReadable($row)) {
                return $this->resourceDenied('parent_not_accessible');
            }
            if (empty($root) || !$this->isPostReadable($root, $actor, $checkBlocks)) {
                return $this->resourceDenied('post_not_accessible');
            }
        }
        $isOwner = (string)($row['author_user_id'] ?? '') === $actor->userId;
        $allowed = match ($action) {
            'read', 'comment', 'like' => !empty($root)
                && $this->isPostReadable($root, $actor, $checkBlocks)
                && (!$checkBlocks || !$this->isBlocked($actor->userId, (string)($row['author_user_id'] ?? ''))),
            'delete', 'update', 'status' => $isOwner,
            default => false,
        };

        $resourceAuthorUserId = (string)($row['author_user_id'] ?? '0');
        $rootAuthorUserId = (string)($root['author_user_id'] ?? '0');
        if ($allowed && (!$this->isPositiveId($resourceAuthorUserId) || !$this->isPositiveId($rootAuthorUserId))) {
            return $this->resourceDenied('author_unavailable');
        }

        return [
            'allowed' => $allowed,
            'reason' => $allowed ? 'ok' : 'forbidden',
            'resource_author_user_id' => $allowed ? $resourceAuthorUserId : '0',
            'root_author_user_id' => $allowed ? $rootAuthorUserId : '0',
        ];
    }

    /** @return array{allowed:false,reason:string,resource_author_user_id:string,root_author_user_id:string} */
    private function resourceDenied(string $reason): array
    {
        return [
            'allowed' => false,
            'reason' => $reason,
            'resource_author_user_id' => '0',
            'root_author_user_id' => '0',
        ];
    }

    /** @return array<string, mixed>|null */
    private function findPost(string $postId): ?array
    {
        if (!$this->isPositiveId($postId)) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT * FROM "posts" WHERE "id" = :id LIMIT 1');
        $statement->execute(['id' => $postId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function findCommentRow(string $commentId): ?array
    {
        if (!$this->isPositiveId($commentId)) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT * FROM "comments" WHERE "id" = :id LIMIT 1');
        $statement->execute(['id' => $commentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function projectPost(array $row): PublicPost
    {
        $postId = (string)$row['id'];
        $statement = $this->pdo->prepare('SELECT * FROM "post_media" WHERE "post_id" = :post_id ORDER BY "sort_order", "id"');
        $statement->execute(['post_id' => $postId]);
        $media = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $media[] = new MediaItem([
                'type' => (int)$item['type'],
                'object_key' => (string)$item['object_key'],
                'mime_type' => (string)$item['mime_type'],
                'width' => (int)($item['width'] ?? 0),
                'height' => (int)($item['height'] ?? 0),
                'duration_ms' => (int)($item['duration_ms'] ?? 0),
                'sort_order' => (int)$item['sort_order'],
            ]);
        }

        return new PublicPost([
            'post_id' => $postId,
            'type' => (int)$row['type'],
            'content' => (string)($row['content'] ?? ''),
            'visibility' => (int)$row['visibility'],
            'emotion' => (string)($row['emotion'] ?? ''),
            'status' => (int)$row['status'],
            'moderation_status' => (int)$row['moderation_status'],
            'like_count' => (string)$row['like_count'],
            'comment_count' => (string)$row['comment_count'],
            'view_count' => (string)$row['view_count'],
            'media' => $media,
            'published_at' => (string)($row['published_at'] ?? ''),
            'created_at' => (string)$row['created_at'],
            'author' => $this->projectAuthor($row, 'post', $postId),
        ]);
    }

    private function projectComment(array $row): PublicComment
    {
        $commentId = (string)$row['id'];
        return new PublicComment([
            'comment_id' => $commentId,
            'post_id' => (string)$row['post_id'],
            'parent_id' => (string)($row['parent_id'] ?? 0),
            'reply_to_comment_id' => (string)($row['reply_to_comment_id'] ?? 0),
            'content' => (string)$row['content'],
            'status' => (int)$row['status'],
            'like_count' => (string)$row['like_count'],
            'created_at' => (string)$row['created_at'],
            'author' => $this->projectAuthor($row, 'post', (string)$row['post_id']),
        ]);
    }

    private function projectAuthor(array $row, string $scopeType, string $scopeId): PublicAuthor
    {
        $author = $this->projector->project([
            'identity_mode' => (int)($row['identity_mode'] ?? 0),
            'persona_id' => isset($row['persona_id']) ? (string)$row['persona_id'] : null,
            'display_name' => $row['author_display_name'] ?? null,
            'avatar_key' => $row['author_avatar_key'] ?? null,
            'anonymous_ref' => $row['author_anonymous_ref'] ?? null,
            'scope_type' => $row['author_scope_type'] ?? null,
            'scope_id' => $row['author_scope_id'] ?? null,
        ], $scopeType, $scopeId);
        $mode = ($author['identity_mode'] ?? 'alias') === 'main'
            ? ProtoIdentityMode::IDENTITY_MODE_MAIN
            : ProtoIdentityMode::IDENTITY_MODE_ALIAS;

        return new PublicAuthor([
            'identity_mode' => $mode,
            'persona_id' => $mode === ProtoIdentityMode::IDENTITY_MODE_MAIN ? (string)($author['persona_id'] ?? 0) : 0,
            'display_name' => (string)($author['display_name'] ?? '匿名用户'),
            'avatar_key' => (string)($author['avatar_key'] ?? 'system:anonymous'),
            'anonymous_ref' => $mode === ProtoIdentityMode::IDENTITY_MODE_ALIAS ? (string)($author['anonymous_ref'] ?? '') : '',
            'profile_accessible' => (bool)($author['profile_accessible'] ?? false),
        ]);
    }

    private function exactColumn(string $column, string $parameter): string
    {
        $quoted = '"' . $column . '"';
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            return $quoted . ' COLLATE "C" = :' . $parameter;
        }

        return $quoted . ' = :' . $parameter . ' COLLATE BINARY';
    }

    private function isBlocked(string $actorUserId, string $targetUserId): bool
    {
        if (blank($targetUserId) || $targetUserId === $actorUserId) {
            return false;
        }

        // Block state belongs to SocialService. A failed internal lookup
        // propagates as an unavailable authorization dependency and therefore
        // fails closed instead of treating a missing DB as "not blocked".
        return $this->blocks->isBlockedEitherWay($actorUserId, $targetUserId);
    }

    private function assertPostReadable(array $row, ?BusinessActor $actor): void
    {
        if (!$this->isPostReadable($row, $actor)) {
            if ((int)($row['status'] ?? 0) !== 1 || ($row['deleted_at'] ?? null) !== null) {
                throw new RuntimeException(ErrorCode::CONTENT_POST_DELETED->message(), ErrorCode::CONTENT_POST_DELETED->value);
            }
            throw new RuntimeException(ErrorCode::PERSONA_FORBIDDEN->message(), ErrorCode::PERSONA_FORBIDDEN->value);
        }
    }

    private function isPostReadable(array $row, ?BusinessActor $actor, bool $checkBlocks = true): bool
    {
        if ((int)($row['status'] ?? 0) !== 1 || ($row['deleted_at'] ?? null) !== null || (int)($row['moderation_status'] ?? 1) === 2) {
            return false;
        }
        if ($checkBlocks && $actor !== null && $this->isBlocked($actor->userId, (string)($row['author_user_id'] ?? ''))) {
            return false;
        }
        $visibility = (int)($row['visibility'] ?? 1);
        if ($visibility === 1) {
            return true;
        }

        return $actor !== null && (string)($row['author_user_id'] ?? '') === $actor->userId;
    }

    private function assertOwner(array $row, BusinessActor $actor): void
    {
        if ((string)($row['author_user_id'] ?? '') !== $actor->userId) {
            throw new RuntimeException(ErrorCode::PERSONA_FORBIDDEN->message(), ErrorCode::PERSONA_FORBIDDEN->value);
        }
    }

    private function assertCommentBelongsToPost(string $commentId, string $postId): void
    {
        $row = $this->findCommentRow($commentId);
        if (empty($row) || (string)$row['post_id'] !== $postId || (int)$row['status'] !== 1 || $row['deleted_at'] !== null) {
            throw new RuntimeException(ErrorCode::CONTENT_COMMENT_NOT_FOUND->message(), ErrorCode::CONTENT_COMMENT_NOT_FOUND->value);
        }
    }

    /**
     * A deleted parent/reply must not keep a child comment addressable.
     *
     * @param array<string, mixed> $cache comment rows (or null) keyed by comment id
     * @param-out array<string, mixed> $cache
     */
    private function isCommentChainReadable(array $row, array &$cache = []): bool
    {
        foreach (['parent_id', 'reply_to_comment_id'] as $column) {
            $id = trim((string)($row[$column] ?? ''));
            if (empty($id)) {
                continue;
            }
            if (!array_key_exists($id, $cache)) {
                $cache[$id] = $this->findCommentRow($id);
            }
            $parent = $cache[$id];
            if (empty($parent) || (int)($parent['status'] ?? 0) !== 1 || ($parent['deleted_at'] ?? null) !== null) {
                return false;
            }
        }

        return true;
    }

    private function resolveIdentity(BusinessActor $actor, int $mode, PersonaOperation $operation): UserIdentity
    {
        $requested = PersonaMode::tryFrom($mode);
        if ($requested === null || $requested === PersonaMode::UNSPECIFIED) {
            throw new RuntimeException('identity mode is required', ErrorCode::PERSONA_FORBIDDEN->value);
        }

        return $this->identities->resolve($actor->userId, $requested, $operation, $actor);
    }

    private function aliasDisplayName(string $requested): string
    {
        $requested = trim($requested);
        if (blank($requested)) {
            return '匿名用户';
        }
        if ($this->length($requested) > self::ALIAS_DISPLAY_NAME_MAX) {
            throw new RuntimeException('alias display name is invalid', ErrorCode::INVALID_ARGUMENT->value);
        }

        return $requested;
    }

    private function assertRequestedPersona(string $requestedPersonaId, UserIdentity $identity): void
    {
        if (!empty($requestedPersonaId) && $requestedPersonaId !== $identity->personaId) {
            throw new RuntimeException(ErrorCode::PERSONA_FORBIDDEN->message(), ErrorCode::PERSONA_FORBIDDEN->value);
        }
    }

    private function insertMedia(string $postId, array $media, string $now): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO "post_media" ("id", "post_id", "type", "object_key", "mime_type", "width", "height", '
            . '"duration_ms", "sort_order", "created_at") VALUES (:id, :post_id, :type, :object_key, :mime_type, '
            . ':width, :height, :duration_ms, :sort_order, :created_at)',
        );
        foreach (array_values($media) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $objectKey = $this->requiredText((string)($item['object_key'] ?? ''), 512, 'media object_key');
            $statement->execute([
                'id' => $this->ids->nextId(),
                'post_id' => $postId,
                'type' => (int)($item['type'] ?? 1),
                'object_key' => $objectKey,
                'mime_type' => $this->requiredText((string)($item['mime_type'] ?? 'application/octet-stream'), 128, 'media mime_type'),
                'width' => (int)($item['width'] ?? 0),
                'height' => (int)($item['height'] ?? 0),
                'duration_ms' => (int)($item['duration_ms'] ?? 0),
                'sort_order' => (int)($item['sort_order'] ?? $index),
                'created_at' => $now,
            ]);
        }
    }

    private function countComments(string $postId, ?string $parentId): int
    {
        $sql = 'SELECT COUNT(*) FROM "comments" AS "c" WHERE "c"."post_id" = :post_id AND "c"."status" = 1 AND "c"."deleted_at" IS NULL'
            . ' AND ("c"."parent_id" IS NULL OR EXISTS ('
            . 'SELECT 1 FROM "comments" AS "parent" WHERE "parent"."id" = "c"."parent_id"'
            . ' AND "parent"."post_id" = "c"."post_id" AND "parent"."status" = 1 AND "parent"."deleted_at" IS NULL))'
            . ' AND ("c"."reply_to_comment_id" IS NULL OR EXISTS ('
            . 'SELECT 1 FROM "comments" AS "reply" WHERE "reply"."id" = "c"."reply_to_comment_id"'
            . ' AND "reply"."post_id" = "c"."post_id" AND "reply"."status" = 1 AND "reply"."deleted_at" IS NULL))';
        $params = ['post_id' => $postId];
        if ($parentId === null) {
            $sql .= ' AND "c"."parent_id" IS NULL';
        } else {
            $sql .= ' AND "c"."parent_id" = :parent_id';
            $params['parent_id'] = $parentId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return (int)$statement->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByRequest(string $table, string $userId, string $requestId): ?array
    {
        if (!in_array($table, ['posts', 'comments'], true)) {
            throw new RuntimeException('invalid idempotency table', ErrorCode::INTERNAL_SERVER_ERROR->value);
        }
        $statement = $this->pdo->prepare(
            'SELECT * FROM "' . $table . '" WHERE "author_user_id" = :user_id AND "request_id" = :request_id LIMIT 1',
        );
        $statement->execute(['user_id' => $userId, 'request_id' => $requestId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function isUniqueViolation(PDOException $exception): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return (int)($exception->errorInfo[1] ?? 0) === 19;
        }
        return PostgresErrorClassifier::isUniqueViolation($exception);
    }

    private function recordEvent(string $aggregateType, string $aggregateId, string $eventType, string $requestId, array $payload): void
    {
        if ($this->outbox === null) {
            return;
        }
        $this->outbox->recordEvent(
            eventId: $this->ids->nextId(),
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            eventType: $eventType,
            eventVersion: 1,
            payload: $payload,
            eventKey: filled($requestId) ? $requestId : $aggregateId,
            traceId: TraceContext::current()->traceId,
        );
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s.v');
    }

    private function requestId(string $value): ?string
    {
        $value = trim($value);
        if (blank($value)) {
            return null;
        }
        if ($this->length($value) > 64) {
            throw new RuntimeException('request_id is invalid', ErrorCode::INVALID_ARGUMENT->value);
        }

        return $value;
    }

    private function requiredText(string $value, int $maxLength, string $field): string
    {
        $value = trim($value);
        if (blank($value) || $this->length($value) > $maxLength) {
            throw new RuntimeException($field . ' is invalid', ErrorCode::INVALID_ARGUMENT->value);
        }

        return $value;
    }

    private function nullableText(string $value, int $maxLength): ?string
    {
        $value = trim($value);
        if (blank($value)) {
            return null;
        }
        if ($this->length($value) > $maxLength) {
            throw new RuntimeException('emotion is invalid', ErrorCode::INVALID_ARGUMENT->value);
        }

        return $value;
    }

    private function nullableId(string $value): ?string
    {
        $value = trim($value);
        if (empty($value)) {
            return null;
        }
        if (!$this->isPositiveId($value)) {
            throw new RuntimeException('id is invalid', ErrorCode::INVALID_ARGUMENT->value);
        }

        return $value;
    }

    private function isPositiveId(string $value): bool
    {
        return preg_match('/\A[1-9][0-9]*\z/', $value) === 1 && strlen($value) <= 20;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
