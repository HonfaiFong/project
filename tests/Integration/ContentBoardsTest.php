<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\Common\V1\PaginationRequest;
use Contracts\Gen\Content\V1\BoardScope;
use Contracts\Gen\Common\V1\IdentityMode;
use Packages\GrpcCommon\BusinessActor;
use Packages\Identity\AnonymousAuthorProjector;
use Packages\Identity\AnonymousAuthorSnapshotFactory;
use Packages\Identity\AnonymousReferenceFactory;
use Packages\Identity\PersonaMode;
use Packages\IdGenerator\Snowflake;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Services\ContentService\Application\ContentApplicationService;
use Tests\Support\ContentTestBlockResolver;
use Tests\Support\ContentTestIdentityDirectory;

/**
 * Board listings, the MAIN persona home and the own management view, plus the
 * per-post identity lock that decides who may speak with which identity.
 */
final class ContentBoardsTest extends TestCase
{
    private PDO $pdo;
    private ContentApplicationService $content;
    private ContentTestBlockResolver $blocks;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for the isolated content boards test.');
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
CREATE TABLE post_participants (
    id INTEGER PRIMARY KEY,
    post_id INTEGER NOT NULL,
    user_id TEXT NOT NULL,
    persona_id TEXT NOT NULL,
    identity_mode INTEGER NOT NULL,
    display_name TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (post_id, user_id)
);
SQL);

        $this->blocks = new ContentTestBlockResolver();
        $references = new AnonymousReferenceFactory(str_repeat('content-test-key-', 3));
        $this->content = new ContentApplicationService(
            $this->pdo,
            new Snowflake(1),
            new ContentTestIdentityDirectory(),
            $this->blocks,
            new AnonymousAuthorSnapshotFactory($references),
            new AnonymousAuthorProjector($references),
        );
    }

    public function testBoardsSplitByPostAuthorIdentityMode(): void
    {
        $mainPost = $this->content->createPost(new BusinessActor('10'), PersonaMode::MAIN->value, '0', 1, 'main post', 1, '', [], 'main-1');
        $aliasPost = $this->content->createPost(new BusinessActor('10'), PersonaMode::ALIAS->value, '0', 1, 'alias post', 1, '', [], 'alias-1', '橘子');

        $mainBoard = $this->content->listPosts(new BusinessActor('30'), BoardScope::BOARD_SCOPE_MAIN, '', IdentityMode::IDENTITY_MODE_UNSPECIFIED, null);
        $aliasBoard = $this->content->listPosts(new BusinessActor('30'), BoardScope::BOARD_SCOPE_ALIAS, '', IdentityMode::IDENTITY_MODE_UNSPECIFIED, null);

        self::assertSame([(string)$mainPost->getPostId()], array_map(static fn ($p) => (string)$p->getPostId(), $mainBoard['posts']));
        self::assertSame([(string)$aliasPost->getPostId()], array_map(static fn ($p) => (string)$p->getPostId(), $aliasBoard['posts']));

        $projectedAliasAuthor = $aliasBoard['posts'][0]->getAuthor();
        self::assertSame('橘子', $projectedAliasAuthor->getDisplayName());
        self::assertSame(0, $projectedAliasAuthor->getPersonaId());
        self::assertNotSame('', $projectedAliasAuthor->getAnonymousRef());
        self::assertFalse($projectedAliasAuthor->getProfileAccessible());
        $projectedMainAuthor = $mainBoard['posts'][0]->getAuthor();
        self::assertSame('110', (string)$projectedMainAuthor->getPersonaId());
    }

    public function testBoardExcludesPrivateHiddenDeletedAndUnapprovedPosts(): void
    {
        $actor = new BusinessActor('10');
        $this->content->createPost($actor, PersonaMode::MAIN->value, '0', 1, 'private', 2, '', [], 'private-1');
        $hidden = $this->content->createPost($actor, PersonaMode::MAIN->value, '0', 1, 'hidden', 1, '', [], 'hidden-1');
        $deleted = $this->content->createPost($actor, PersonaMode::MAIN->value, '0', 1, 'deleted', 1, '', [], 'deleted-1');
        $this->content->updatePostStatus((string)$hidden->getPostId(), 2, 1, 'hide-1');
        $this->content->deletePost($actor, (string)$deleted->getPostId(), 'delete-1');
        $this->content->createPost($actor, PersonaMode::MAIN->value, '0', 1, 'rejected', 1, '', [], 'rejected-1');
        $this->pdo->exec('UPDATE posts SET moderation_status = 2 WHERE content = \'rejected\'');

        $board = $this->content->listPosts(new BusinessActor('30'), BoardScope::BOARD_SCOPE_MAIN, '', IdentityMode::IDENTITY_MODE_UNSPECIFIED, null);
        self::assertSame([], $board['posts']);
        self::assertSame(0, (int)(string)$board['pagination']->getTotal());
    }

    public function testBoardHidesBlockedAuthorsInBothDirections(): void
    {
        $a = new BusinessActor('10');
        $b = new BusinessActor('20');
        $postA = $this->content->createPost($a, PersonaMode::MAIN->value, '0', 1, 'from a', 1, '', [], 'a-1');
        $postB = $this->content->createPost($b, PersonaMode::MAIN->value, '0', 1, 'from b', 1, '', [], 'b-1');
        $this->blocks->blocked['10|20'] = true;

        $forA = $this->content->listPosts($a, BoardScope::BOARD_SCOPE_MAIN, '', IdentityMode::IDENTITY_MODE_UNSPECIFIED, null);
        $forB = $this->content->listPosts($b, BoardScope::BOARD_SCOPE_MAIN, '', IdentityMode::IDENTITY_MODE_UNSPECIFIED, null);

        self::assertSame([(string)$postA->getPostId()], array_map(static fn ($p) => (string)$p->getPostId(), $forA['posts']));
        self::assertSame([(string)$postB->getPostId()], array_map(static fn ($p) => (string)$p->getPostId(), $forB['posts']));
    }

    public function testPersonaHomeOnlyListsPublicMainPostsOfThatPersona(): void
    {
        $a = new BusinessActor('10');
        $mainPost = $this->content->createPost($a, PersonaMode::MAIN->value, '0', 1, 'public main', 1, '', [], 'main-1');
        $this->content->createPost($a, PersonaMode::MAIN->value, '0', 1, 'private main', 2, '', [], 'main-2');
        $this->content->createPost($a, PersonaMode::ALIAS->value, '0', 1, 'alias', 1, '', [], 'alias-1', '橘子');
        $other = $this->content->createPost(new BusinessActor('20'), PersonaMode::MAIN->value, '0', 1, 'other main', 1, '', [], 'main-3');

        $home = $this->content->listPosts(new BusinessActor('30'), BoardScope::BOARD_SCOPE_PERSONA_HOME, '110', IdentityMode::IDENTITY_MODE_UNSPECIFIED, null);
        self::assertSame([(string)$mainPost->getPostId()], array_map(static fn ($p) => (string)$p->getPostId(), $home['posts']));
        self::assertSame(0, (int)(string)$home['pagination']->getTotal());

        $otherHome = $this->content->listPosts(new BusinessActor('30'), BoardScope::BOARD_SCOPE_PERSONA_HOME, '210', IdentityMode::IDENTITY_MODE_UNSPECIFIED, null);
        self::assertSame([(string)$other->getPostId()], array_map(static fn ($p) => (string)$p->getPostId(), $otherHome['posts']));
    }

    public function testPersonaHomeRequiresPersonaId(): void
    {
        try {
            $this->content->listPosts(new BusinessActor('30'), BoardScope::BOARD_SCOPE_PERSONA_HOME, '', IdentityMode::IDENTITY_MODE_UNSPECIFIED, null);
            self::fail('persona home without persona_id must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame(ErrorCode::INVALID_ARGUMENT->value, $exception->getCode());
        }
    }

    public function testOwnViewReturnsBothIdentitiesAndStatesForTheAccountOnly(): void
    {
        $a = new BusinessActor('10');
        $main = $this->content->createPost($a, PersonaMode::MAIN->value, '0', 1, 'main', 1, '', [], 'main-1');
        $alias = $this->content->createPost($a, PersonaMode::ALIAS->value, '0', 1, 'alias', 1, '', [], 'alias-1', '橘子');
        $hidden = $this->content->createPost($a, PersonaMode::MAIN->value, '0', 1, 'hidden', 1, '', [], 'main-2');
        $this->content->updatePostStatus((string)$hidden->getPostId(), 2, 1, 'hide-1');
        $deleted = $this->content->createPost($a, PersonaMode::MAIN->value, '0', 1, 'deleted', 1, '', [], 'main-3');
        $this->content->deletePost($a, (string)$deleted->getPostId(), 'delete-1');
        $this->content->createPost(new BusinessActor('20'), PersonaMode::MAIN->value, '0', 1, 'other', 1, '', [], 'other-1');

        $own = $this->content->listPosts($a, BoardScope::BOARD_SCOPE_OWN, '', IdentityMode::IDENTITY_MODE_UNSPECIFIED, null);
        $ownIds = array_map(static fn ($p) => (string)$p->getPostId(), $own['posts']);
        sort($ownIds, SORT_STRING);
        $expected = [(string)$alias->getPostId(), (string)$hidden->getPostId(), (string)$main->getPostId()];
        sort($expected, SORT_STRING);
        self::assertSame($expected, $ownIds);
        self::assertSame(3, (int)(string)$own['pagination']->getTotal());
        self::assertFalse($own['pagination']->getHasMore());

        $aliasOnly = $this->content->listPosts($a, BoardScope::BOARD_SCOPE_OWN, '', IdentityMode::IDENTITY_MODE_ALIAS, null);
        self::assertSame([(string)$alias->getPostId()], array_map(static fn ($p) => (string)$p->getPostId(), $aliasOnly['posts']));
    }

    public function testOwnViewRequiresActor(): void
    {
        try {
            $this->content->listPosts(null, BoardScope::BOARD_SCOPE_OWN, '', IdentityMode::IDENTITY_MODE_UNSPECIFIED, null);
            self::fail('own view without actor must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame(ErrorCode::AUTH_UNAUTHORIZED->value, $exception->getCode());
        }
    }

    public function testBoardCursorPaginationIsStableAcrossPages(): void
    {
        $actor = new BusinessActor('10');
        $ids = [];
        foreach (['one', 'two', 'three'] as $index => $word) {
            $ids[] = (string)$this->content
                ->createPost($actor, PersonaMode::MAIN->value, '0', 1, $word, 1, '', [], 'page-' . $index)
                ->getPostId();
        }

        $pagination = new PaginationRequest(['page_size' => 2]);
        $first = $this->content->listPosts(new BusinessActor('30'), BoardScope::BOARD_SCOPE_MAIN, '', IdentityMode::IDENTITY_MODE_UNSPECIFIED, $pagination);
        self::assertCount(2, $first['posts']);
        self::assertTrue($first['pagination']->getHasMore());
        self::assertNotSame('', $first['pagination']->getNextCursor());
        self::assertSame(0, (int)(string)$first['pagination']->getTotal());

        $second = $this->content->listPosts(
            new BusinessActor('30'),
            BoardScope::BOARD_SCOPE_MAIN,
            '',
            IdentityMode::IDENTITY_MODE_UNSPECIFIED,
            new PaginationRequest(['page_size' => 2, 'cursor' => $first['pagination']->getNextCursor()]),
        );
        self::assertCount(1, $second['posts']);
        self::assertFalse($second['pagination']->getHasMore());

        $seen = array_map(static fn ($p) => (string)$p->getPostId(), [...$first['posts'], ...$second['posts']]);
        self::assertSame(array_reverse($ids), $seen);
    }

    public function testPerPostIdentityLockRejectsModeSwitchWithinOnePost(): void
    {
        $post = $this->content->createPost(new BusinessActor('10'), PersonaMode::MAIN->value, '0', 1, 'post', 1, '', [], 'main-1');
        $commenter = new BusinessActor('20');

        $this->content->createComment($commenter, PersonaMode::ALIAS->value, '0', (string)$post->getPostId(), '0', '0', 'first', 'c-1', '游客');

        try {
            $this->content->createComment($commenter, PersonaMode::MAIN->value, '0', (string)$post->getPostId(), '0', '0', 'switch', 'c-2');
            self::fail('Switching identity inside one post must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame(ErrorCode::PERSONA_FORBIDDEN->value, $exception->getCode());
        }
    }

    public function testAliasNicknameIsLockedAfterFirstUsePerPost(): void
    {
        $post = $this->content->createPost(new BusinessActor('10'), PersonaMode::MAIN->value, '0', 1, 'post', 1, '', [], 'main-1');
        $commenter = new BusinessActor('20');

        $first = $this->content->createComment($commenter, PersonaMode::ALIAS->value, '0', (string)$post->getPostId(), '0', '0', 'first', 'c-1', '一颗橘子');
        $second = $this->content->createComment($commenter, PersonaMode::ALIAS->value, '0', (string)$post->getPostId(), '0', '0', 'second', 'c-2', '别的名字');

        self::assertSame('一颗橘子', $first->getAuthor()->getDisplayName());
        self::assertSame('一颗橘子', $second->getAuthor()->getDisplayName());
        self::assertSame($first->getAuthor()->getAnonymousRef(), $second->getAuthor()->getAnonymousRef());
    }

    public function testAliasPostAuthorIsLockedToAliasAndKeepsNickname(): void
    {
        $post = $this->content->createPost(new BusinessActor('10'), PersonaMode::ALIAS->value, '0', 1, 'post', 1, '', [], 'alias-1', '楼主橘子');
        $author = new BusinessActor('10');

        try {
            $this->content->createComment($author, PersonaMode::MAIN->value, '0', (string)$post->getPostId(), '0', '0', 'reveal', 'c-1');
            self::fail('The alias post author must not comment as MAIN in the own post.');
        } catch (RuntimeException $exception) {
            self::assertSame(ErrorCode::PERSONA_FORBIDDEN->value, $exception->getCode());
        }

        $reply = $this->content->createComment($author, PersonaMode::ALIAS->value, '0', (string)$post->getPostId(), '0', '0', 'reply', 'c-2', '被忽略的新昵称');
        self::assertSame('楼主橘子', $reply->getAuthor()->getDisplayName());
    }

    public function testIdentityLockIsScopedPerPost(): void
    {
        $postOne = $this->content->createPost(new BusinessActor('10'), PersonaMode::MAIN->value, '0', 1, 'one', 1, '', [], 'main-1');
        $postTwo = $this->content->createPost(new BusinessActor('10'), PersonaMode::MAIN->value, '0', 1, 'two', 1, '', [], 'main-2');
        $postThree = $this->content->createPost(new BusinessActor('10'), PersonaMode::MAIN->value, '0', 1, 'three', 1, '', [], 'main-3');
        $commenter = new BusinessActor('20');

        $first = $this->content->createComment($commenter, PersonaMode::ALIAS->value, '0', (string)$postOne->getPostId(), '0', '0', 'first', 'c-1', '一个身份');
        $second = $this->content->createComment($commenter, PersonaMode::MAIN->value, '0', (string)$postTwo->getPostId(), '0', '0', 'second', 'c-2');
        // postTwo 内首次行动已把身份固定为 MAIN，之后不能在同一帖切换。
        try {
            $this->content->createComment($commenter, PersonaMode::ALIAS->value, '0', (string)$postTwo->getPostId(), '0', '0', 'switch', 'c-3', '切换');
            self::fail('The per-post identity lock must reject a later mode switch.');
        } catch (RuntimeException $exception) {
            self::assertSame(ErrorCode::PERSONA_FORBIDDEN->value, $exception->getCode());
        }
        $again = $this->content->createComment($commenter, PersonaMode::MAIN->value, '0', (string)$postTwo->getPostId(), '0', '0', 'again', 'c-4');
        // 锁定范围是单帖：新帖里首次行动可以重新选择身份，且匿名标识不复用。
        $third = $this->content->createComment($commenter, PersonaMode::ALIAS->value, '0', (string)$postThree->getPostId(), '0', '0', 'third', 'c-5', '另一个身份');

        self::assertSame(2, $first->getAuthor()->getIdentityMode());
        self::assertSame(1, $second->getAuthor()->getIdentityMode());
        self::assertSame(1, $again->getAuthor()->getIdentityMode());
        self::assertSame(2, $third->getAuthor()->getIdentityMode());
        self::assertNotSame($first->getAuthor()->getAnonymousRef(), $third->getAuthor()->getAnonymousRef());
    }

    public function testAliasDisplayNameOverTwentyCharactersIsRejected(): void
    {
        $post = $this->content->createPost(new BusinessActor('10'), PersonaMode::MAIN->value, '0', 1, 'post', 1, '', [], 'main-1');

        try {
            $this->content->createComment(
                new BusinessActor('20'),
                PersonaMode::ALIAS->value,
                '0',
                (string)$post->getPostId(),
                '0',
                '0',
                'comment',
                'c-1',
                '这个匿名昵称远远超过二十个字的限制必须被拒绝',
            );
            self::fail('An alias nickname beyond 20 characters must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame(ErrorCode::INVALID_ARGUMENT->value, $exception->getCode());
        }
    }

    public function testCommenterLockSurvivesIdempotentRetryWithDifferentNickname(): void
    {
        $post = $this->content->createPost(new BusinessActor('10'), PersonaMode::MAIN->value, '0', 1, 'post', 1, '', [], 'main-1');
        $commenter = new BusinessActor('20');

        $first = $this->content->createComment($commenter, PersonaMode::ALIAS->value, '0', (string)$post->getPostId(), '0', '0', 'same', 'c-1', '原名');
        $replay = $this->content->createComment($commenter, PersonaMode::ALIAS->value, '0', (string)$post->getPostId(), '0', '0', 'same', 'c-1', '新名字');

        self::assertSame($first->getCommentId(), $replay->getCommentId());
        self::assertSame('原名', $replay->getAuthor()->getDisplayName());
    }

    public function testHistoricalRootAndCommentLocksSurviveUpgrade(): void
    {
        $owner = new BusinessActor('10');
        $post = $this->content->createPost($owner, 2, '0', 1, 'old', 1, '', [], 'old-post', 'Original');
        $this->content->createComment(new BusinessActor('20'), 2, '0', (string)$post->getPostId(), '0', '0', 'old comment', 'old-c', 'First');
        $this->pdo->exec('DELETE FROM post_participants');
        foreach ([$owner, new BusinessActor('20')] as $actor) {
            try {
                $this->content->createComment($actor, 1, '0', (string)$post->getPostId(), '0', '0', 'switch', 'switch-' . $actor->userId);
                self::fail('A historical speaker cannot change mode');
            } catch (RuntimeException $e) {
                self::assertSame(ErrorCode::PERSONA_FORBIDDEN->value, $e->getCode());
            }
        }
        $reply = $this->content->createComment($owner, 2, '0', (string)$post->getPostId(), '0', '0', 'reply', 'old-reply', 'Changed');
        self::assertSame('Original', $reply->getAuthor()->getDisplayName());
    }

    public function testBlockedTailIsBoundedBatchedAndCursorAdvances(): void
    {
        for ($i = 100; $i < 221; $i++) {
            $this->content->createPost(new BusinessActor((string)$i), 1, '0', 1, 'post', 1, '', [], 'tail-' . $i);
        }
        $blocks = new class implements \Services\ContentService\Domain\Social\BatchBlockStatusResolver {
            public array $sizes = [];
            public function isBlockedEitherWay(string $firstUserId, string $secondUserId): bool { throw new RuntimeException('N+1 block query'); }
            public function blockedUserIds(string $actor, array $targets): array { $this->sizes[] = count($targets); return $targets; }
        };
        $references = new AnonymousReferenceFactory(str_repeat('content-test-key-', 3));
        $content = new ContentApplicationService($this->pdo, new Snowflake(2), new ContentTestIdentityDirectory(), $blocks, new AnonymousAuthorSnapshotFactory($references), new AnonymousAuthorProjector($references));
        $first = $content->listPosts(new BusinessActor('999'), BoardScope::BOARD_SCOPE_MAIN, '', 0, new PaginationRequest(['page_size' => 1]));
        self::assertSame([], $first['posts']);
        self::assertTrue($first['pagination']->getHasMore());
        self::assertNotSame('', $first['pagination']->getNextCursor());
        self::assertSame(0, (int)$first['pagination']->getTotal());
        $second = $content->listPosts(new BusinessActor('999'), BoardScope::BOARD_SCOPE_MAIN, '', 0, new PaginationRequest(['page_size' => 1, 'cursor' => $first['pagination']->getNextCursor()]));
        self::assertFalse($second['pagination']->getHasMore());
        self::assertSame([100, 21], $blocks->sizes);
    }

    public function testInvalidCursorValuesAreRejectedBeforeDatabaseQuery(): void
    {
        foreach (['not-a-date|1', '2026-02-30T00:00:00Z|1', '2026-10-30T00:00:00Z|18446744073709551615', '0000-01-01T00:00:00Z|1'] as $value) {
            try {
                $this->content->listPosts(new BusinessActor('20'), BoardScope::BOARD_SCOPE_MAIN, '', 0, new PaginationRequest(['cursor' => base64_encode($value)]));
                self::fail('Invalid cursor was accepted: ' . $value);
            } catch (RuntimeException $e) {
                self::assertSame(ErrorCode::INVALID_ARGUMENT->value, $e->getCode());
            }
        }
    }
}
