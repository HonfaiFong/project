<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contracts\Errors\ErrorCode;
use Packages\GrpcCommon\BusinessActor;
use Packages\Identity\AnonymousAuthorProjector;
use Packages\Identity\AnonymousAuthorSnapshotFactory;
use Packages\Identity\AnonymousReferenceFactory;
use Packages\Identity\PersonaMode;
use Packages\Identity\PersonaOperation;
use Packages\Identity\UserIdentity;
use Packages\Identity\UserIdentityDirectory;
use Packages\IdGenerator\Snowflake;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Services\ContentService\Application\ContentApplicationService;
use Services\ContentService\Domain\Social\BlockStatusResolver;

/** Exercises the content boundary with real PDO persistence and projections. */
final class ContentAuthorizationTest extends TestCase
{
    private PDO $pdo;
    private ContentApplicationService $content;
    private ContentTestIdentityDirectory $identities;
    private ContentTestBlockResolver $blocks;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for the isolated content authorization test.');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE posts (
    id INTEGER PRIMARY KEY,
    author_user_id TEXT NOT NULL,
    request_id TEXT NULL,
    persona_id TEXT NOT NULL,
    identity_mode INTEGER NOT NULL,
    author_display_name TEXT NULL,
    author_avatar_key TEXT NULL,
    author_anonymous_ref TEXT NULL,
    author_scope_type TEXT NULL,
    author_scope_id TEXT NULL,
    type INTEGER NOT NULL,
    content TEXT NOT NULL,
    visibility INTEGER NOT NULL,
    emotion TEXT NULL,
    status INTEGER NOT NULL,
    moderation_status INTEGER NOT NULL,
    like_count INTEGER NOT NULL DEFAULT 0,
    comment_count INTEGER NOT NULL DEFAULT 0,
    view_count INTEGER NOT NULL DEFAULT 0,
    published_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL
);
CREATE TABLE comments (
    id INTEGER PRIMARY KEY,
    post_id INTEGER NOT NULL,
    author_user_id TEXT NOT NULL,
    request_id TEXT NULL,
    persona_id TEXT NOT NULL,
    identity_mode INTEGER NOT NULL,
    author_display_name TEXT NULL,
    author_avatar_key TEXT NULL,
    author_anonymous_ref TEXT NULL,
    author_scope_type TEXT NULL,
    author_scope_id TEXT NULL,
    parent_id INTEGER NULL,
    reply_to_comment_id INTEGER NULL,
    content TEXT NOT NULL,
    status INTEGER NOT NULL,
    like_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL
);
CREATE TABLE post_media (
    id INTEGER PRIMARY KEY,
    post_id INTEGER NOT NULL,
    type INTEGER NOT NULL,
    object_key TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,
    duration_ms INTEGER NOT NULL,
    sort_order INTEGER NOT NULL,
    created_at TEXT NOT NULL
);
SQL);

        $this->identities = new ContentTestIdentityDirectory();
        $this->blocks = new ContentTestBlockResolver();
        $references = new AnonymousReferenceFactory(str_repeat('content-test-key-', 3));
        $this->content = new ContentApplicationService(
            $this->pdo,
            new Snowflake(1),
            $this->identities,
            $this->blocks,
            new AnonymousAuthorSnapshotFactory($references),
            new AnonymousAuthorProjector($references),
        );
    }

    public function testZeroContentAndRequestKeyRemainIdempotentForBothIdentityModes(): void
    {
        foreach ([PersonaMode::MAIN, PersonaMode::ALIAS] as $mode) {
            $actor = new BusinessActor((string)(10 * $mode->value));
            $first = $this->content->createPost($actor, $mode->value, '0', 1, '0', 1, '', [], '0', '0');
            $retry = $this->content->createPost($actor, $mode->value, '0', 1, '0', 1, '', [], '0', '0');
            self::assertSame($first->getPostId(), $retry->getPostId());
            self::assertSame('0', $first->getContent());
            if ($mode === PersonaMode::ALIAS) {
                self::assertSame('0', $first->getAuthor()->getDisplayName());
                self::assertFalse($first->getAuthor()->getProfileAccessible());
            }
            try {
                $this->content->createPost($actor, $mode->value, '0', 1, 'changed', 1, '', [], '0', '0');
                self::fail('The zero request key must still detect conflicting replays');
            } catch (RuntimeException $exception) {
                self::assertSame(ErrorCode::IDEMPOTENCY_CONFLICT->value, $exception->getCode());
            }
        }
        self::assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn());
    }

    public function testWhitespaceContentIsRejectedBeforePersistence(): void
    {
        $this->expectExceptionCode(ErrorCode::INVALID_ARGUMENT->value);
        $this->content->createPost(new BusinessActor('10'), PersonaMode::MAIN->value, '0', 1, '   ', 1, '', [], 'blank-content');
    }

    public function testAnonymousPostAndCommentPersistSnapshotsAndScopeReference(): void
    {
        $actor = new BusinessActor('10');
        $post = $this->content->createPost(
            $actor,
            PersonaMode::ALIAS->value,
            '0',
            1,
            'anonymous post',
            1,
            '',
            [],
            'post-request-1',
            'post alias',
        );
        $postAuthor = $post->getAuthor();

        $this->assertSame(2, $postAuthor->getIdentityMode());
        $this->assertSame(0, $postAuthor->getPersonaId());
        $this->assertSame('post alias', $postAuthor->getDisplayName());
        $this->assertNotSame('', $postAuthor->getAnonymousRef());

        $samePost = $this->content->createPost(
            $actor,
            PersonaMode::ALIAS->value,
            '0',
            1,
            'anonymous post',
            1,
            '',
            [],
            'post-request-1',
            'post alias',
        );
        $this->assertSame($post->getPostId(), $samePost->getPostId());
        $this->assertSame('anonymous post', $samePost->getContent());

        try {
            $this->content->createPost($actor, PersonaMode::ALIAS->value, '0', 1, 'must be idempotent', 1, '', [], 'post-request-1');
            $this->fail('A reused request_id with different parameters must conflict.');
        } catch (RuntimeException $exception) {
            $this->assertSame(ErrorCode::IDEMPOTENCY_CONFLICT->value, $exception->getCode());
        }

        $comment = $this->content->createComment(
            $actor,
            PersonaMode::ALIAS->value,
            '0',
            (string)$post->getPostId(),
            '0',
            '0',
            'anonymous comment',
            'comment-request-1',
            'comment alias',
        );
        $commentAuthor = $comment->getAuthor();
        $this->assertSame(2, $commentAuthor->getIdentityMode());
        $this->assertSame('comment alias', $commentAuthor->getDisplayName());
        $this->assertSame($postAuthor->getAnonymousRef(), $commentAuthor->getAnonymousRef());
        $this->assertSame(0, $commentAuthor->getPersonaId());
    }

    public function testPrivateReadAndRequestedPersonaAuthorizationUseTrustedActor(): void
    {
        $owner = new BusinessActor('10');
        $post = $this->content->createPost($owner, PersonaMode::MAIN->value, '110', 1, 'private', 2, '', [], 'private-1');

        $this->assertSame($post->getPostId(), $this->content->getPost($owner, (string)$post->getPostId())->getPostId());
        $this->expectExceptionCode(ErrorCode::PERSONA_FORBIDDEN->value);
        $this->content->getPost(new BusinessActor('20'), (string)$post->getPostId());
    }

    public function testForgedPersonaAndBlockedResourceAreDeniedAndResourceProjectionIsZeroed(): void
    {
        $owner = new BusinessActor('20');
        try {
            $this->content->createPost($owner, PersonaMode::MAIN->value, '999', 1, 'forged', 1, '', [], 'forged-1');
            $this->fail('A persona selected by the request must belong to the trusted actor.');
        } catch (RuntimeException $exception) {
            $this->assertSame(ErrorCode::PERSONA_FORBIDDEN->value, $exception->getCode());
        }

        $post = $this->content->createPost($owner, PersonaMode::MAIN->value, '210', 1, 'blocked', 1, '', [], 'blocked-1');
        $this->blocks->blocked['10|20'] = true;
        $blockedActor = new BusinessActor('10');

        try {
            $this->content->getPost($blockedActor, (string)$post->getPostId());
            $this->fail('A blocked account must not read the post.');
        } catch (RuntimeException $exception) {
            $this->assertSame(ErrorCode::PERSONA_FORBIDDEN->value, $exception->getCode());
        }

        $resource = $this->content->canAccessResource($blockedActor, 'post', (string)$post->getPostId(), 'like');
        $this->assertFalse($resource['allowed']);
        $this->assertSame('0', $resource['resource_author_user_id']);
        $this->assertSame('0', $resource['root_author_user_id']);
    }
}

final class ContentTestIdentityDirectory implements UserIdentityDirectory
{
    public function resolve(string $userId, PersonaMode $mode, PersonaOperation $operation, ?BusinessActor $actor = null): UserIdentity
    {
        if ($actor === null || $actor->userId !== $userId) {
            throw new RuntimeException('actor mismatch');
        }
        $personaId = $mode === PersonaMode::MAIN ? (string)((int)$userId * 10 + 10) : (string)((int)$userId * 10 + 1);

        return new UserIdentity($userId, $personaId, $mode, $mode === PersonaMode::MAIN ? 'Main ' . $userId : 'Internal alias', null, $mode === PersonaMode::MAIN);
    }

    public function validate(string $userId, string $personaId, PersonaOperation $operation, ?BusinessActor $actor = null): UserIdentity
    {
        throw new RuntimeException('not used');
    }

    public function main(string $personaId, PersonaOperation $operation, ?BusinessActor $actor = null): UserIdentity
    {
        throw new RuntimeException('not used');
    }
}

final class ContentTestBlockResolver implements BlockStatusResolver
{
    /** @var array<string,bool> */
    public array $blocked = [];

    public function isBlockedEitherWay(string $firstUserId, string $secondUserId): bool
    {
        return ($this->blocked[$firstUserId . '|' . $secondUserId] ?? false)
            || ($this->blocked[$secondUserId . '|' . $firstUserId] ?? false);
    }
}
