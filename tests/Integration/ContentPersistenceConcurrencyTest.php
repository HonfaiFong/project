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
use Packages\Outbox\EventOutboxInterface;
use Packages\Outbox\OutboxRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Services\ContentService\Application\ContentApplicationService;
use Services\ContentService\Domain\Social\BlockStatusResolver;
use Throwable;

/**
 * Exercises the content write boundary against real MySQL connections: transaction atomicity for
 * delete/status writes, affected-row event gating, comment counter upkeep, and request_id reuse
 * (including concurrent duplicate-key recovery).
 */
final class ContentPersistenceConcurrencyTest extends TestCase
{
    private const LOCK_TIMEOUT_SECONDS = 5;
    private const DATABASE_WAIT_SECONDS = 10.0;

    private ?PDO $pdo = null;
    private string $databaseName = '';

    /** @var array{process: resource, ready: string, done: string}|null */
    private ?array $collisionWriter = null;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('SOCIAL_TEST_MYSQL_DATABASE');
        if (!$database) {
            $this->markTestSkipped('SOCIAL_TEST_MYSQL_DATABASE is not configured');
        }
        if (!str_starts_with($database, 'social_test_')) {
            throw new RuntimeException('An isolated social_test_ database is required');
        }
        $this->databaseName = $database;
        $this->pdo = $this->connect();
        $this->createSchema($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->stopCollisionWriter();
        if ($this->pdo instanceof PDO) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->dropSchema($this->pdo);
            $this->pdo = null;
        }
        parent::tearDown();
    }

    public function testDeletePostKeepsPostAndEventAtomicAndIsIdempotent(): void
    {
        $outbox = new RecordingContentOutbox();
        $service = $this->service($this->pdo, $outbox);
        $post = $service->createPost(new BusinessActor('10'), PersonaMode::MAIN->value, '110', 1, 'delete me', 1, '', [], 'req-post-1');
        $outbox->events = [];

        $service->deletePost(new BusinessActor('10'), (string)$post->getPostId(), 'req-delete-1');
        $this->assertSame(0, (int)$this->scalar('SELECT `status` FROM `posts` WHERE `id` = ?', [(string)$post->getPostId()]));
        $this->assertSame(['post.deleted.v1'], $this->eventTypes($outbox));

        // A repeated delete must not emit a second event or fail.
        $service->deletePost(new BusinessActor('10'), (string)$post->getPostId(), 'req-delete-2');
        $this->assertCount(1, $outbox->events);
        $this->assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM `outbox_events` WHERE `event_type` = ?', ['post.deleted.v1']));
    }

    public function testDeleteCommentRollsBackCommentCountAndEventTogether(): void
    {
        $outbox = new RecordingContentOutbox();
        $service = $this->service($this->pdo, $outbox);
        $actor = new BusinessActor('10');
        $post = $service->createPost($actor, PersonaMode::MAIN->value, '110', 1, 'post', 1, '', [], 'req-post-1');
        $comment = $service->createComment($actor, PersonaMode::MAIN->value, '110', (string)$post->getPostId(), '0', '0', 'comment', 'req-comment-1');
        $this->assertSame(1, (int)$this->scalar('SELECT `comment_count` FROM `posts` WHERE `id` = ?', [(string)$post->getPostId()]));
        $outbox->events = [];

        $service->deleteComment($actor, (string)$comment->getCommentId(), 'req-delete-comment');
        $this->assertSame(0, (int)$this->scalar('SELECT `comment_count` FROM `posts` WHERE `id` = ?', [(string)$post->getPostId()]));
        $this->assertSame(0, (int)$this->scalar('SELECT `status` FROM `comments` WHERE `id` = ?', [(string)$comment->getCommentId()]));
        $this->assertSame(['comment.deleted.v1'], $this->eventTypes($outbox));

        // A repeated delete must not decrement the counter or emit another event.
        $service->deleteComment($actor, (string)$comment->getCommentId(), 'req-delete-comment-2');
        $this->assertSame(0, (int)$this->scalar('SELECT `comment_count` FROM `posts` WHERE `id` = ?', [(string)$post->getPostId()]));
        $this->assertSame(['comment.deleted.v1'], $this->eventTypes($outbox));
    }

    public function testDeleteFailsClosedWhenTheOutboxWriteFails(): void
    {
        $healthy = $this->service($this->pdo, new RecordingContentOutbox());
        $post = $healthy->createPost(new BusinessActor('10'), PersonaMode::MAIN->value, '110', 1, 'atomic', 1, '', [], 'req-post-1');

        $failing = $this->service($this->pdo, new FailingContentOutbox());
        try {
            $failing->deletePost(new BusinessActor('10'), (string)$post->getPostId(), 'req-delete-1');
            $this->fail('A failed outbox write must abort the delete.');
        } catch (RuntimeException $exception) {
            $this->assertSame('outbox is unavailable', $exception->getMessage());
        }

        $this->assertSame(1, (int)$this->scalar('SELECT `status` FROM `posts` WHERE `id` = ?', [(string)$post->getPostId()]));
        $this->assertNull($this->scalar('SELECT `deleted_at` FROM `posts` WHERE `id` = ?', [(string)$post->getPostId()]));
        // Only the create and the comment events remain; the aborted delete wrote nothing.
        $this->assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM `outbox_events`'));
    }

    public function testDeleteRollbackReleasesRowLocksForTheNextWriter(): void
    {
        $service = $this->service($this->pdo, new RecordingContentOutbox());
        $actor = new BusinessActor('10');
        $post = $service->createPost($actor, PersonaMode::MAIN->value, '110', 1, 'lock probe', 1, '', [], 'req-post-1');
        $probe = null;

        try {
            $this->service($this->pdo, new FailingContentOutbox())
                ->deletePost($actor, (string)$post->getPostId(), 'req-delete-1');
            $this->fail('A failed outbox write must abort the delete.');
        } catch (RuntimeException) {
            // The rollback must have released the row; a second connection can lock it immediately.
            $probe = $this->connect();
            $probe->beginTransaction();
            $statement = $probe->prepare('SELECT `id` FROM `posts` WHERE `id` = ? FOR UPDATE');
            $statement->execute([(string)$post->getPostId()]);
            $this->assertSame((string)$post->getPostId(), (string)$statement->fetchColumn());
        } finally {
            if ($probe instanceof PDO && $probe->inTransaction()) {
                $probe->rollBack();
            }
        }

        $this->assertSame(1, (int)$this->scalar('SELECT `status` FROM `posts` WHERE `id` = ?', [(string)$post->getPostId()]));
        $this->assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM `outbox_events`'));
    }

    public function testCommentCountReturnsToZeroAfterEveryCreatedCommentIsDeleted(): void
    {
        $service = $this->service($this->pdo, new RecordingContentOutbox());
        $actor = new BusinessActor('10');
        $post = $service->createPost($actor, PersonaMode::MAIN->value, '110', 1, 'post', 1, '', [], 'req-post-1');
        $postId = (string)$post->getPostId();
        $first = $service->createComment($actor, PersonaMode::MAIN->value, '110', $postId, '0', '0', 'one', 'req-comment-1');
        $second = $service->createComment($actor, PersonaMode::MAIN->value, '110', $postId, '0', '0', 'two', 'req-comment-2');
        $this->assertSame(2, (int)$this->scalar('SELECT `comment_count` FROM `posts` WHERE `id` = ?', [$postId]));

        $service->deleteComment($actor, (string)$first->getCommentId(), 'req-delete-1');
        $this->assertSame(1, (int)$this->scalar('SELECT `comment_count` FROM `posts` WHERE `id` = ?', [$postId]));
        $service->deleteComment($actor, (string)$second->getCommentId(), 'req-delete-2');
        $this->assertSame(0, (int)$this->scalar('SELECT `comment_count` FROM `posts` WHERE `id` = ?', [$postId]));
    }

    public function testCreatePostAndCommentRejectTheSameRequestIdWithDifferentParameters(): void
    {
        $service = $this->service($this->pdo, new RecordingContentOutbox());
        $actor = new BusinessActor('10');
        $post = $service->createPost($actor, PersonaMode::MAIN->value, '110', 1, 'original', 1, '', [], 'req-shared-1');

        // Same business identity with identical parameters is an idempotent replay.
        $replay = $service->createPost($actor, PersonaMode::MAIN->value, '110', 1, 'original', 1, '', [], 'req-shared-1');
        $this->assertSame((string)$post->getPostId(), (string)$replay->getPostId());

        try {
            $service->createPost($actor, PersonaMode::MAIN->value, '110', 1, 'changed content', 1, '', [], 'req-shared-1');
            $this->fail('A reused request_id with different parameters must conflict.');
        } catch (RuntimeException $exception) {
            $this->assertSame(ErrorCode::IDEMPOTENCY_CONFLICT->value, $exception->getCode());
        }
        try {
            $service->createPost($actor, PersonaMode::MAIN->value, '110', 1, 'original', 1, '', [['object_key' => 'media/1', 'mime_type' => 'image/png']], 'req-shared-1');
            $this->fail('A reused request_id with different media must conflict.');
        } catch (RuntimeException $exception) {
            $this->assertSame(ErrorCode::IDEMPOTENCY_CONFLICT->value, $exception->getCode());
        }
        try {
            $service->createPost($actor, PersonaMode::ALIAS->value, '101', 1, 'original', 1, '', [], 'req-shared-1');
            $this->fail('A reused request_id with a different identity must conflict.');
        } catch (RuntimeException $exception) {
            $this->assertSame(ErrorCode::IDEMPOTENCY_CONFLICT->value, $exception->getCode());
        }
        $this->assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM `posts` WHERE `author_user_id` = ?', ['10']));

        $comment = $service->createComment($actor, PersonaMode::MAIN->value, '110', (string)$post->getPostId(), '0', '0', 'first', 'req-shared-2');
        $commentReplay = $service->createComment($actor, PersonaMode::MAIN->value, '110', (string)$post->getPostId(), '0', '0', 'first', 'req-shared-2');
        $this->assertSame((string)$comment->getCommentId(), (string)$commentReplay->getCommentId());
        try {
            $service->createComment($actor, PersonaMode::MAIN->value, '110', (string)$post->getPostId(), '0', '0', 'second', 'req-shared-2');
            $this->fail('A reused comment request_id with different parameters must conflict.');
        } catch (RuntimeException $exception) {
            $this->assertSame(ErrorCode::IDEMPOTENCY_CONFLICT->value, $exception->getCode());
        }
        try {
            $service->createComment($actor, PersonaMode::ALIAS->value, '101', (string)$post->getPostId(), '0', '0', 'first', 'req-shared-2');
            $this->fail('A reused comment request_id with a different identity must conflict.');
        } catch (RuntimeException $exception) {
            $this->assertSame(ErrorCode::IDEMPOTENCY_CONFLICT->value, $exception->getCode());
        }
        $this->assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM `comments`'));
    }

    public function testAliasDisplayNameIsPartOfTheIdempotentRequest(): void
    {
        $service = $this->service($this->pdo, new RecordingContentOutbox());
        $actor = new BusinessActor('10');
        $post = $service->createPost($actor, PersonaMode::ALIAS->value, '101', 1, 'post', 1, '', [], 'req-alias-post', 'First alias');
        $comment = $service->createComment($actor, PersonaMode::ALIAS->value, '101', (string)$post->getPostId(), '0', '0', 'comment', 'req-alias-comment', 'First alias');
        foreach ([
            fn () => $service->createPost($actor, PersonaMode::ALIAS->value, '101', 1, 'post', 1, '', [], 'req-alias-post', 'Other alias'),
            fn () => $service->createComment($actor, PersonaMode::ALIAS->value, '101', (string)$post->getPostId(), '0', '0', 'comment', 'req-alias-comment', 'Other alias'),
        ] as $retry) {
            try {
                $retry();
                $this->fail('A changed anonymous display name must conflict.');
            } catch (RuntimeException $exception) {
                $this->assertSame(ErrorCode::IDEMPOTENCY_CONFLICT->value, $exception->getCode());
            }
        }
        $this->assertSame((string)$comment->getCommentId(), (string)$service->createComment($actor, PersonaMode::ALIAS->value, '101', (string)$post->getPostId(), '0', '0', 'comment', 'req-alias-comment', 'First alias')->getCommentId());
    }

    public function testPostMediaOrderChangesConflictWhileIdenticalRetriesReplay(): void
    {
        $service = $this->service($this->pdo, new RecordingContentOutbox());
        $actor = new BusinessActor('10');
        $first = ['type' => 1, 'object_key' => 'media/first', 'mime_type' => 'image/png'];
        $second = ['type' => 1, 'object_key' => 'media/second', 'mime_type' => 'image/png'];
        $post = $service->createPost($actor, PersonaMode::MAIN->value, '110', 1, 'with media', 1, '', [$first, $second], 'req-media-1');

        $this->assertSame(
            ['media/first', 'media/second'],
            $this->column('SELECT `object_key` FROM `post_media` WHERE `post_id` = ? ORDER BY `sort_order`', [(string)$post->getPostId()]),
        );
        $replay = $service->createPost($actor, PersonaMode::MAIN->value, '110', 1, 'with media', 1, '', [$first, $second], 'req-media-1');
        $this->assertSame((string)$post->getPostId(), (string)$replay->getPostId());

        try {
            $service->createPost($actor, PersonaMode::MAIN->value, '110', 1, 'with media', 1, '', [$second, $first], 'req-media-1');
            $this->fail('A reordered media retry must conflict.');
        } catch (RuntimeException $exception) {
            $this->assertSame(ErrorCode::IDEMPOTENCY_CONFLICT->value, $exception->getCode());
        }
    }

    public function testIdenticalMediaRetryWithExplicitSortOrderIsCanonical(): void
    {
        $service = $this->service($this->pdo, new RecordingContentOutbox());
        $actor = new BusinessActor('10');
        $media = [
            ['object_key' => 'media/later', 'sort_order' => 2],
            ['object_key' => 'media/first', 'sort_order' => 1],
            ['object_key' => 'media/tied', 'sort_order' => 1],
        ];
        $post = $service->createPost($actor, PersonaMode::MAIN->value, '110', 1, 'ordered', 1, '', $media, 'req-sort');
        $replay = $service->createPost($actor, PersonaMode::MAIN->value, '110', 1, 'ordered', 1, '', $media, 'req-sort');
        $this->assertSame((string)$post->getPostId(), (string)$replay->getPostId());
        $this->assertSame(['media/first', 'media/tied', 'media/later'],
            $this->column('SELECT object_key FROM post_media ORDER BY sort_order, id'));
    }

    public function testConcurrentCommentCollisionRecoversThroughDuplicateKeyLookup(): void
    {
        $outbox = new RecordingContentOutbox();
        $service = $this->service($this->pdo, $outbox);
        $actor = new BusinessActor('10');
        $post = $service->createPost($actor, PersonaMode::MAIN->value, '110', 1, 'post', 1, '', [], 'req-post-1');
        $postId = (string)$post->getPostId();

        // An exception after the Outbox INSERT but before COMMIT must roll back both writes.
        $crashed = $this->service($this->pdo, new FailAfterOutboxWrite());
        try {
            $crashed->createComment($actor, PersonaMode::MAIN->value, '110', $postId, '0', '0', 'same payload', 'req-crash-1');
            $this->fail('The simulated crash must surface.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated failure after outbox write', $exception->getMessage());
        }
        $this->assertSame(0, (int)$this->scalar('SELECT COUNT(*) FROM `comments` WHERE `request_id` = ?', ['req-crash-1']));

        // The competitor holds the unique-index lock until the service INSERT visibly waits on it,
        // then commits the identical comment; the retry must recover through the duplicate-key
        // lookup and reuse the committed row.
        $this->startCollisionWriter('9223372036854775002', $postId, 'req-race-1', 'same payload');
        try {
            $recovered = $service->createComment($actor, PersonaMode::MAIN->value, '110', $postId, '0', '0', 'same payload', 'req-race-1');
        } finally {
            $this->waitForCollisionDone();
        }

        $this->assertSame('9223372036854775002', (string)$recovered->getCommentId());
        $this->assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM `comments` WHERE `request_id` = ?', ['req-race-1']));
        $this->assertSame(0, (int)$this->scalar('SELECT COUNT(*) FROM `outbox_events` WHERE `event_type` = ?', ['comment.created.v1']));
    }

    public function testConcurrentDuplicateKeyCollisionIsReportedAsIdempotencyConflict(): void
    {
        $service = $this->service($this->pdo, new RecordingContentOutbox());
        $actor = new BusinessActor('10');
        $post = $service->createPost($actor, PersonaMode::MAIN->value, '110', 1, 'post', 1, '', [], 'req-post-1');
        $postId = (string)$post->getPostId();

        // The competitor commits a different payload under the same (author, request_id) while the
        // retry INSERT waits on its index lock; the loser must report a conflict instead of
        // returning the winner's payload.
        $this->startCollisionWriter('9223372036854775001', $postId, 'req-conflict-1', 'winner payload');
        try {
            $service->createComment($actor, PersonaMode::MAIN->value, '110', $postId, '0', '0', 'loser payload', 'req-conflict-1');
            $this->fail('A concurrent duplicate-key collision must surface as an idempotency conflict.');
        } catch (RuntimeException $exception) {
            $this->assertSame(ErrorCode::IDEMPOTENCY_CONFLICT->value, $exception->getCode());
        } finally {
            $this->waitForCollisionDone();
        }

        $this->assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM `comments` WHERE `request_id` = ?', ['req-conflict-1']));
        $this->assertSame('winner payload', (string)$this->scalar('SELECT `content` FROM `comments` WHERE `request_id` = ?', ['req-conflict-1']));
    }
    public function testStatusUpdateGatesTheEventOnAnActuallyChangedRow(): void
    {
        $outbox = new RecordingContentOutbox();
        $service = $this->service($this->pdo, $outbox);
        $post = $service->createPost(new BusinessActor('10'), PersonaMode::MAIN->value, '110', 1, 'moderated', 1, '', [], 'req-post-1');
        $postId = (string)$post->getPostId();
        $outbox->events = [];

        $service->updatePostStatus($postId, 3, 2, 'req-status-1');
        $this->assertSame(['post.status.updated.v1'], $this->eventTypes($outbox));
        $this->assertSame(3, (int)$this->scalar('SELECT `status` FROM `posts` WHERE `id` = ?', [$postId]));

        $outbox->events = [];
        usleep(10000);
        $service->updatePostStatus($postId, 3, 2, 'req-status-replay');
        $this->assertSame([], $outbox->events);
        $this->assertSame(2, (int)$this->scalar('SELECT COUNT(*) FROM `outbox_events`'));

        // A missing row must fail without emitting an event; the soft-deleted row leaves the update
        // with zero affected rows and must also emit nothing.
        $this->pdo->prepare('DELETE FROM `posts` WHERE `id` = ?')->execute([$postId]);
        $outbox->events = [];
        try {
            $service->updatePostStatus($postId, 2, 2, 'req-status-2');
            $this->fail('A missing post must not accept a status update.');
        } catch (RuntimeException $exception) {
            $this->assertSame(ErrorCode::CONTENT_POST_NOT_FOUND->value, $exception->getCode());
        }
        $this->assertSame([], $outbox->events);
        $this->assertSame(2, (int)$this->scalar('SELECT COUNT(*) FROM `outbox_events`'));
    }

    public function testListCommentsBatchesChainLookupsAndBlockChecksPerAuthor(): void
    {
        $blocks = new CountingBlockResolver();
        $service = $this->service($this->pdo, new RecordingContentOutbox(), $blocks);
        $owner = new BusinessActor('10');
        $post = $service->createPost($owner, PersonaMode::MAIN->value, '110', 1, 'post', 1, '', [], 'req-post-1');
        $postId = (string)$post->getPostId();
        $first = $service->createComment($owner, PersonaMode::MAIN->value, '110', $postId, '0', '0', 'first', 'req-comment-1');
        $second = $service->createComment(new BusinessActor('20'), PersonaMode::MAIN->value, '210', $postId, '0', '0', 'second', 'req-comment-2');
        $third = $service->createComment(new BusinessActor('30'), PersonaMode::MAIN->value, '310', $postId, '0', '0', 'third', 'req-comment-3');
        // Replies carry parent/reply references that force a chain lookup for every listed row.
        foreach ([$first, $second, $third] as $index => $parent) {
            $service->createComment(
                $owner,
                PersonaMode::MAIN->value,
                '110',
                $postId,
                (string)$parent->getCommentId(),
                (string)$parent->getCommentId(),
                'reply ' . $index,
                'req-reply-' . $index,
            );
        }

        $blocks->calls = [];
        $page = $service->listComments($owner, $postId, '', null);
        $this->assertCount(3, $page['comments']);
        // One block RPC per distinct author on the page; the actor short-circuits.
        ksort($blocks->calls);
        $this->assertSame(['20' => 1, '30' => 1], $blocks->calls);
        $this->assertSame(3, $page['pagination']->getTotal());
    }

    private function service(PDO $pdo, EventOutboxInterface $outbox, ?BlockStatusResolver $blocks = null): ContentApplicationService
    {
        if ($outbox instanceof RecordingContentOutbox) {
            $outbox->bind($pdo);
        }
        if ($outbox instanceof FailAfterOutboxWrite) {
            $outbox->bind($pdo);
        }
        $references = new AnonymousReferenceFactory(str_repeat('content-test-key-', 3));

        return new ContentApplicationService(
            $pdo,
            new Snowflake(1),
            new ContentPersistenceIdentityDirectory(),
            $blocks ?? new CountingBlockResolver(),
            new AnonymousAuthorSnapshotFactory($references),
            new AnonymousAuthorProjector($references),
            $outbox,
        );
    }

    private function connect(): PDO
    {
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            getenv('SOCIAL_TEST_MYSQL_HOST') ?: '127.0.0.1',
            (int)(getenv('SOCIAL_TEST_MYSQL_PORT') ?: 3306), $this->databaseName),
            getenv('SOCIAL_TEST_MYSQL_USER') ?: 'root', getenv('SOCIAL_TEST_MYSQL_PASSWORD') ?: '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_FOUND_ROWS => true,
            ]);
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');

        return $pdo;
    }

    /**
     * Starts the competitor fixture: it inserts one comment and holds the unique-index lock until the
     * service INSERT is visibly waiting on that lock (probed through performance_schema), then
     * commits so the caller observes a genuine duplicate-key collision.
     */
    private function startCollisionWriter(string $commentId, string $postId, string $requestId, string $content): void
    {
        $prefix = tempnam(sys_get_temp_dir(), 'content-collision-');
        if ($prefix === false) {
            throw new RuntimeException('Unable to create a collision coordination path');
        }
        unlink($prefix);
        $ready = $prefix . '-ready';
        $done = $prefix . '-done';
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/fixtures/ContentCommentCollisionWorker.php',
                $ready,
                $done,
                $this->databaseName,
                $commentId,
                $postId,
                $requestId,
                $content,
            ],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the collision writer');
        }
        fclose($pipes[0]);
        $this->collisionWriter = ['process' => $process, 'ready' => $ready, 'done' => $done];
        $this->waitForPath($ready, 'collision writer did not take the unique-index lock');
    }

    /** The fixture commits once the blocked INSERT is observed; the caller must finish the write first. */
    private function waitForCollisionDone(): void
    {
        $writer = $this->requireCollisionWriter();
        $this->waitForPath($writer['done'], 'collision writer did not commit');
        $this->assertSame('done', file_get_contents($writer['done']), 'Competitor must observe the blocked INSERT and commit');
    }

    private function stopCollisionWriter(): void
    {
        $writer = $this->collisionWriter;
        if ($writer === null) {
            return;
        }
        $this->collisionWriter = null;
        if (!is_file($writer['done'])) {
            $deadline = microtime(true) + self::DATABASE_WAIT_SECONDS;
            while (!is_file($writer['done']) && microtime(true) < $deadline) {
                usleep(10000);
            }
        }
        if (!is_file($writer['done'])) {
            proc_terminate($writer['process']);
        }
        foreach ([$writer['ready'], $writer['done']] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        proc_close($writer['process']);
    }

    /** @return array{process: resource, ready: string, done: string} */
    private function requireCollisionWriter(): array
    {
        if ($this->collisionWriter === null) {
            throw new RuntimeException('The collision writer was not started');
        }

        return $this->collisionWriter;
    }

    private function waitForPath(string $path, string $message): void
    {
        $deadline = microtime(true) + self::DATABASE_WAIT_SECONDS + self::LOCK_TIMEOUT_SECONDS;
        while (!is_file($path) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (!is_file($path)) {
            throw new RuntimeException($message);
        }
    }

    private function createSchema(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS `posts`, `comments`, `post_media`, `outbox_events`');
        $pdo->exec(<<<'SQL'
CREATE TABLE `posts` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `author_user_id` BIGINT UNSIGNED NULL,
  `request_id` VARCHAR(64) NULL,
  `persona_id` BIGINT UNSIGNED NOT NULL,
  `identity_mode` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `author_display_name` VARCHAR(64) NULL,
  `author_avatar_key` VARCHAR(255) NULL,
  `author_anonymous_ref` VARCHAR(128) NULL,
  `author_scope_type` VARCHAR(255) NULL,
  `author_scope_id` VARCHAR(255) NULL,
  `type` SMALLINT NOT NULL DEFAULT 1,
  `content` TEXT NULL,
  `visibility` SMALLINT NOT NULL DEFAULT 1,
  `emotion` VARCHAR(32) NULL,
  `status` SMALLINT NOT NULL DEFAULT 1,
  `moderation_status` SMALLINT NOT NULL DEFAULT 1,
  `like_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `comment_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `view_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `published_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `deleted_at` DATETIME(3) NULL,
  UNIQUE KEY `uniq_author_request` (`author_user_id`, `request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE `comments` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `author_user_id` BIGINT UNSIGNED NULL,
  `request_id` VARCHAR(64) NULL,
  `persona_id` BIGINT UNSIGNED NOT NULL,
  `identity_mode` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `author_display_name` VARCHAR(64) NULL,
  `author_avatar_key` VARCHAR(255) NULL,
  `author_anonymous_ref` VARCHAR(128) NULL,
  `author_scope_type` VARCHAR(255) NULL,
  `author_scope_id` VARCHAR(255) NULL,
  `parent_id` BIGINT UNSIGNED NULL,
  `reply_to_comment_id` BIGINT UNSIGNED NULL,
  `content` VARCHAR(2000) NOT NULL,
  `status` SMALLINT NOT NULL DEFAULT 1,
  `like_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `deleted_at` DATETIME(3) NULL,
  UNIQUE KEY `uniq_author_request` (`author_user_id`, `request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE `post_media` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `type` SMALLINT NOT NULL DEFAULT 1,
  `object_key` VARCHAR(512) NOT NULL,
  `mime_type` VARCHAR(128) NOT NULL,
  `width` INT NULL,
  `height` INT NULL,
  `duration_ms` INT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE `outbox_events` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `event_id` VARCHAR(36) NOT NULL,
  `aggregate_type` VARCHAR(64) NOT NULL,
  `aggregate_id` VARCHAR(64) NOT NULL,
  `event_key` VARCHAR(64) NOT NULL DEFAULT '',
  `trace_id` VARCHAR(64) NOT NULL DEFAULT '',
  `event_type` VARCHAR(128) NOT NULL,
  `event_version` INT NOT NULL DEFAULT 1,
  `payload` JSON NOT NULL,
  `status` SMALLINT NOT NULL DEFAULT 0,
  `available_at` DATETIME(3) NOT NULL,
  `published_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  UNIQUE KEY `uniq_event_id` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function dropSchema(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS `posts`, `comments`, `post_media`, `outbox_events`');
    }

    /** @param list<string> $params */
    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    /**
     * @param list<string> $params
     * @return list<mixed>
     */
    private function column(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<string> */
    private function eventTypes(RecordingContentOutbox $outbox): array
    {
        return array_column($outbox->events, 'event_type');
    }
}

final class ContentPersistenceIdentityDirectory implements UserIdentityDirectory
{
    public function resolve(string $userId, PersonaMode $mode, PersonaOperation $operation, ?BusinessActor $actor = null): UserIdentity
    {
        if ($actor === null || $actor->userId !== $userId) {
            throw new RuntimeException('actor mismatch');
        }
        $personaId = $mode === PersonaMode::MAIN ? (string)((int)$userId * 10 + 10) : (string)((int)$userId * 10 + 1);

        return new UserIdentity($userId, $personaId, $mode, 'Main ' . $userId, null, $mode === PersonaMode::MAIN);
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

final class CountingBlockResolver implements BlockStatusResolver
{
    /** @var array<string, int> */
    public array $calls = [];

    public function isBlockedEitherWay(string $firstUserId, string $secondUserId): bool
    {
        $this->calls[$secondUserId] = ($this->calls[$secondUserId] ?? 0) + 1;

        return false;
    }
}

/** Persists events through the real outbox repository so rollback semantics stay observable. */
final class RecordingContentOutbox implements EventOutboxInterface
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    private ?OutboxRepository $repository = null;

    public function bind(PDO $pdo): void
    {
        $this->repository = new OutboxRepository($pdo, new Snowflake(2));
    }

    public function recordEvent(
        string $eventId,
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        int $eventVersion,
        array $payload,
        string $eventKey,
        string $traceId
    ): string {
        $id = $this->repository?->recordEvent(
            $eventId,
            $aggregateType,
            $aggregateId,
            $eventType,
            $eventVersion,
            $payload,
            $eventKey,
            $traceId,
        ) ?? $eventId;
        $this->events[] = [
            'id' => $id,
            'event_id' => $eventId,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'event_key' => $eventKey,
            'payload' => $payload,
        ];

        return $id;
    }
}

final class FailingContentOutbox implements EventOutboxInterface
{
    public function recordEvent(
        string $eventId,
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        int $eventVersion,
        array $payload,
        string $eventKey,
        string $traceId
    ): string {
        throw new RuntimeException('outbox is unavailable');
    }
}

/** Writes the event inside the open transaction, then fails: the rollback must undo both writes. */
final class FailAfterOutboxWrite implements EventOutboxInterface
{
    private ?OutboxRepository $repository = null;

    public function bind(PDO $pdo): void
    {
        $this->repository = new OutboxRepository($pdo, new Snowflake(3));
    }

    public function recordEvent(
        string $eventId,
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        int $eventVersion,
        array $payload,
        string $eventKey,
        string $traceId
    ): string {
        $this->repository?->recordEvent(
            $eventId,
            $aggregateType,
            $aggregateId,
            $eventType,
            $eventVersion,
            $payload,
            $eventKey,
            $traceId,
        );

        throw new RuntimeException('simulated failure after outbox write');
    }
}
