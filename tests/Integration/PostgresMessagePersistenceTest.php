<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contracts\Errors\ErrorCode;
use PDO;
use PDOException;
use Packages\Database\PostgresConnectionFactory;
use Packages\GrpcCommon\OperationBudget;
use Packages\Identity\AnonymousAuthorProjector;
use Packages\Identity\AnonymousReferenceFactory;
use Packages\Outbox\OutboxRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Services\MessageService\Application\MessageApplicationService;
use Services\MessageService\Domain\ChatMemberSnapshotClient;
use Services\MessageService\Domain\Exception\MessageException;
use Services\MessageService\Domain\Repository\MessageWriteTransactionInterface;
use Services\MessageService\Infrastructure\Postgres\PostgresMessageRepository;
use Services\MessageService\Infrastructure\Postgres\PostgresMessageTransactionManager;
use Services\MessageService\Infrastructure\Postgres\MessageArchiveImporter;
use Services\MessageService\Infrastructure\Postgres\PostgresMessageConnection;

/** Real PostgreSQL coverage; truncates only an explicitly selected disposable test_* database. */
final class PostgresMessagePersistenceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (getenv('DATABASE_INTEGRATION') !== '1') {
            self::markTestSkipped('DATABASE_INTEGRATION=1 is required');
        }
        self::assertStringStartsWith('test_', (string)getenv('PG_DATABASE'));
        $this->pdo = $this->connect();
        $this->pdo->exec('TRUNCATE outbox_events, messages, conversation_sequences');
    }

    public function testMessageSequenceAndOutboxCommitAndRollbackTogether(): void
    {
        $this->write('900', '1', 'first');
        try {
            $this->manager()->transaction(function (MessageWriteTransactionInterface $tx): void {
                $this->writeIn($tx, '800', '1', 'aborted');
                throw new RuntimeException('abort');
            });
        } catch (RuntimeException $e) {
            self::assertSame('abort', $e->getMessage());
        }
        $this->write('700', '1', 'second');
        self::assertSame(['1', '2'], array_column($this->repository()->listAfter('1', '0', 10), 'seq'));
        self::assertSame(2, $this->rowCount('messages'));
        self::assertSame(2, $this->rowCount('outbox_events'));
        self::assertSame('2', (string)$this->pdo->query('SELECT last_seq FROM conversation_sequences')->fetchColumn());
    }

    public function testHistoryUsesSequenceInsteadOfMessageIdAndReturnsUtcStrings(): void
    {
        $this->write('900', '1', 'first');
        $this->write('800', '1', 'second');
        $this->pdo->exec("SET TIME ZONE 'Asia/Shanghai'");
        self::assertSame(['2', '1'], array_column($this->repository()->listPage('1', 1, null), 'seq'));
        self::assertSame(['1'], array_column($this->repository()->listPage('1', 1, '2'), 'seq'));
        self::assertSame('2', $this->repository()->latestSequence('1'));
        self::assertSame('0', $this->repository()->latestSequence('2'));
        self::assertSame('2026-10-29 00:00:00.123', $this->repository()->listPage('1', 1, null)[0]['created_at']);
        $this->pdo->exec('SET enable_seqscan = off');
        $plan = $this->pdo->query('EXPLAIN SELECT * FROM messages WHERE conversation_id=1 AND seq<3 ORDER BY seq DESC LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
        self::assertStringContainsString('uniq_conversation_seq', implode('\n', $plan));
    }

    public function testJsonbIdempotencyPreservesPrecisionAndObjectArrayDistinction(): void
    {
        $app = $this->app();
        $request = ['conversation_id' => '1', 'client_message_id' => 'retry', 'type' => 1,
            'content' => '{"b":2,"a":{"id":"9223372036854775807"},"large":9223372036854775808}'];
        $first = $app->sendMessage('100', $request, new OperationBudget(3000));
        $retry = $app->sendMessage('100', array_replace($request, ['content' => '{ "large":9223372036854775808,"a":{"id":"9223372036854775807"},"b":2.0 }']), new OperationBudget(3000));
        self::assertSame((string)$first->getMessageId(), (string)$retry->getMessageId());
        self::assertSame('1', (string)$retry->getSeq());
        self::assertSame(1, $this->rowCount('messages'));
        self::assertSame(1, $this->rowCount('outbox_events'));
        self::assertSame('jsonb', $this->pdo->query('SELECT pg_typeof(content)::text FROM messages')->fetchColumn());
        foreach ([
            ['content' => '{"b":2,"a":{"id":"9223372036854775807"},"large":9223372036854775809}'],
            ['conversation_id' => '2'], ['type' => 2],
        ] as $change) {
            try {
                $app->sendMessage('100', array_replace($request, $change), new OperationBudget(3000));
                self::fail('Changed idempotent payload must fail');
            } catch (MessageException $e) {
                self::assertSame(ErrorCode::MESSAGE_DUPLICATE_CLIENT_ID->value, $e->getCode());
            }
        }
        self::assertFalse($this->repository()->idempotencyMatches(
            ['conversation_id' => '1', 'type' => 1, 'content' => '{}'],
            ['conversation_id' => '1', 'type' => 1, 'content' => '[]'],
        ));
        self::assertFalse($this->repository()->idempotencyMatches(
            ['conversation_id' => '1', 'type' => 1, 'content' => '[1,2]'],
            ['conversation_id' => '1', 'type' => 1, 'content' => '[2,1]'],
        ));
    }

    public function testJsonScalarsAndSignedInt64IdsRoundTripWithoutDoubleEncoding(): void
    {
        $this->write('9223372036854775807', '9223372036854775807', 'max-id', '{"id":"9223372036854775807","text":"你好😀"}');
        $row = $this->repository()->listPage('9223372036854775807', 10, null)[0];
        self::assertSame('9223372036854775807', $row['message_id']);
        self::assertSame('9223372036854775807', json_decode($row['content'], true)['id']);
        self::assertSame('object', $this->pdo->query('SELECT jsonb_typeof(content) FROM messages')->fetchColumn());
        foreach (['0', 'null', '{}', '[]', '"text"'] as $i => $content) {
            $this->write((string)(1000 + $i), '2', 'scalar-' . $i, $content);
            self::assertEquals(json_decode($content), json_decode($this->repository()->findBySenderClientId('100', 'scalar-' . $i)['content']));
        }
    }

    public function testUnsupportedJsonbContentRollsBackSequenceAndOutbox(): void
    {
        try {
            $this->app()->sendMessage('100', ['conversation_id' => '1', 'client_message_id' => 'nul', 'type' => 1, 'content' => '{"text":"\u0000"}']);
            self::fail('JSONB cannot store NUL');
        } catch (MessageException $e) {
            self::assertStringContainsString('JSONB', $e->getMessage());
        }
        foreach (['messages', 'conversation_sequences', 'outbox_events'] as $table) {
            self::assertSame(0, $this->rowCount($table));
        }
    }

    public function testRelayOrdersByMessageSequenceAndDoesNotBlockOtherConversations(): void
    {
        $this->write('900', '1', 'first');
        $this->write('800', '1', 'second');
        $this->write('700', '2', 'unrelated');
        $relay = new OutboxRepository($this->pdo, orderingColumn: 'message_seq');
        $claims = $relay->claimPending(10);
        self::assertSame(['event-700', 'event-900'], array_map(static fn ($c): string => $c->record->eventId, $claims));
        self::assertTrue($relay->markFailed($claims[1], 60));
        self::assertTrue($relay->markPublished($claims[0]));
        self::assertCount(0, $relay->claimPending(10));
        $this->pdo->exec("UPDATE outbox_events SET available_at=CURRENT_TIMESTAMP-INTERVAL '1 second' WHERE id=900");
        $first = $relay->claimPending(1);
        self::assertSame('event-900', $first[0]->record->eventId);
        self::assertTrue($relay->markPublished($first[0]));
        self::assertSame('event-800', $relay->claimPending(1)[0]->record->eventId);
    }

    public function testLeaseTokenFencesAnExpiredRelayWorker(): void
    {
        $this->write('900', '1', 'first');
        $relay = new OutboxRepository($this->pdo, orderingColumn: 'message_seq');
        $old = $relay->claimPending(1)[0];
        $this->pdo->exec("UPDATE outbox_events SET lease_until=CURRENT_TIMESTAMP-INTERVAL '1 second'");
        self::assertSame(1, $relay->releaseStaleClaims());
        $new = $relay->claimPending(1)[0];
        self::assertFalse($relay->markPublished($old));
        self::assertFalse($relay->markFailed($old));
        self::assertTrue($relay->markPublished($new));
    }

    public function testConcurrentSendsAllocateUniqueSequencesAndDeduplicateRaces(): void
    {
        $this->concurrent(false);
        self::assertSame(['1', '2'], array_column($this->repository()->listAfter('1', '0', 10), 'seq'));
        $this->pdo->exec('TRUNCATE outbox_events, messages, conversation_sequences');
        $this->concurrent(true);
        self::assertSame(1, $this->rowCount('messages'));
        self::assertSame(1, $this->rowCount('outbox_events'));
        self::assertSame('1', (string)$this->pdo->query('SELECT last_seq FROM conversation_sequences')->fetchColumn());
    }

    public function testExpiredBudgetAndRowLockWaitCannotLeavePartialWrites(): void
    {
        $budget = new OperationBudget(1);
        usleep(3000);
        try {
            $this->manager()->transaction(fn ($tx) => $this->writeIn($tx, '900', '1', 'expired'), $budget);
            self::fail('Expired request must not write');
        } catch (RuntimeException $e) {
            self::assertSame(ErrorCode::RPC_TIMEOUT->value, $e->getCode());
        }
        self::assertSame(0, $this->rowCount('messages'));
        $this->write('900', '1', 'first');
        $locker = $this->connect();
        $locker->beginTransaction();
        $locker->exec('UPDATE conversation_sequences SET last_seq=last_seq WHERE conversation_id=1');
        try {
            $this->manager()->transaction(fn ($tx) => $this->writeIn($tx, '800', '1', 'locked'), new OperationBudget(100));
            self::fail('Row lock must be bounded by RPC budget');
        } catch (RuntimeException $e) {
            self::assertSame(ErrorCode::RPC_TIMEOUT->value, $e->getCode());
        } finally {
            $locker->rollBack();
        }
        self::assertSame(1, $this->rowCount('messages'));
        self::assertSame('1', (string)$this->pdo->query('SELECT last_seq FROM conversation_sequences')->fetchColumn());
    }

    public function testSerializationFailureReplaysOnlyTheRolledBackTransaction(): void
    {
        $attempts = 0;
        $this->manager()->transaction(function ($tx) use (&$attempts): void {
            $this->writeIn($tx, '900', '1', 'retry-transaction');
            if (++$attempts === 1) {
                $error = new PDOException('injected serialization failure');
                $error->errorInfo = ['40001'];
                throw $error;
            }
        }, new OperationBudget(3000));
        self::assertSame(2, $attempts);
        self::assertSame(1, $this->rowCount('messages'));
        self::assertSame('1', (string)$this->pdo->query('SELECT last_seq FROM conversation_sequences')->fetchColumn());
    }

    public function testLostCommitAcknowledgementDoesNotReplayAndRecoversByClientId(): void
    {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('PG_HOST'), getenv('PG_PORT') ?: '5432', getenv('PG_DATABASE'));
        $connection = new class($dsn, (string)getenv('PG_USER'), (string)getenv('PG_PASSWORD')) extends PDO {
            public int $commits = 0;
            public function commit(): bool
            {
                $result = parent::commit();
                if (++$this->commits === 1) {
                    $exception = new PDOException('injected loss of commit acknowledgement');
                    $exception->errorInfo = ['08006'];
                    throw $exception;
                }
                return $result;
            }
        };
        $connection->exec("SET search_path TO message_db; SET TIME ZONE 'UTC'");
        $reconnections = 0;
        $shared = new PostgresMessageConnection($connection, function () use (&$reconnections): PDO {
            $reconnections++;
            return $this->connect();
        });
        $attempts = 0;
        try {
            (new PostgresMessageTransactionManager($shared))->transaction(function ($tx) use (&$attempts): void {
                $attempts++;
                $this->writeIn($tx, '900', '1', 'commit-ack');
            }, new OperationBudget(3000));
            self::fail('An unconfirmed commit must not be reported as success');
        } catch (RuntimeException $e) {
            self::assertSame(ErrorCode::RPC_TIMEOUT->value, $e->getCode());
        }
        self::assertSame(1, $attempts);
        self::assertSame(1, $connection->commits);
        self::assertSame('900', (new PostgresMessageRepository($shared))->findBySenderClientId('100', 'commit-ack')['message_id']);
        self::assertSame(1, $reconnections);
        $recovered = $this->app()->sendMessage('100', ['conversation_id' => '1', 'client_message_id' => 'commit-ack',
            'type' => 1, 'content' => '{"body":"hello"}'], new OperationBudget(3000));
        self::assertSame('900', (string)$recovered->getMessageId());
        self::assertSame('1', (string)$recovered->getSeq());
        self::assertSame(1, $this->rowCount('outbox_events'));
    }

    public function testMessageRolesHaveOnlyTheirOwnRequiredPrivileges(): void
    {
        foreach ([
            ["has_table_privilege('message_runtime','message_db.messages','SELECT,INSERT')", true],
            ["has_table_privilege('message_runtime','message_db.messages','UPDATE')", false],
            ["has_table_privilege('message_runtime','message_db.outbox_events','SELECT')", false],
            ["has_table_privilege('message_outbox_relay','message_db.outbox_events','SELECT,UPDATE')", true],
            ["has_table_privilege('message_outbox_relay','message_db.messages','SELECT')", false],
            ["has_schema_privilege('message_runtime','message_db','CREATE')", false],
            ["has_table_privilege('message_runtime','user_db.users','SELECT')", false],
            ["has_table_privilege('chat_runtime','message_db.messages','SELECT')", false],
        ] as [$expression, $expected]) {
            self::assertSame($expected, $this->pdo->query('SELECT ' . $expression)->fetchColumn(), $expression);
        }
    }

    public function testLegacyArchivePreservesSnapshotsAndRequeuesOldRelayClaims(): void
    {
        $files = $this->archiveFiles();
        try {
            self::assertSame(['messages' => 1, 'sequences' => 1, 'outbox' => 1], (new MessageArchiveImporter($this->pdo))->import(...$files));
            $row = $this->repository()->listPage('1', 10, null)[0];
            self::assertSame('Frozen name', $row['sender_display_name']);
            self::assertSame('anon_snapshot', $row['sender_anonymous_ref']);
            self::assertSame('2026-10-29 00:00:00.123', $row['created_at']);
            $event = $this->pdo->query("SELECT status,claim_token,payload->'message'->>'type' AS type FROM outbox_events")->fetch(PDO::FETCH_ASSOC);
            self::assertSame(0, $event['status']);
            self::assertNull($event['claim_token']);
            self::assertSame('1', $event['type']);
            self::assertSame('1', $this->repository()->latestSequence('1'));
        } finally {
            foreach ($files as $file) { unlink($file); }
        }
    }

    public function testArchiveMismatchRollsBackAndExistingMessagesCannotBeOverwritten(): void
    {
        $files = $this->archiveFiles();
        try {
            $sequence = json_decode((string)file_get_contents($files[1]), true, flags: JSON_THROW_ON_ERROR);
            $sequence['last_seq'] = ['$numberLong' => '2'];
            file_put_contents($files[1], json_encode($sequence, JSON_THROW_ON_ERROR));
            try {
                (new MessageArchiveImporter($this->pdo))->import(...$files);
                self::fail('Mismatched archive must fail');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('do not match', $e->getMessage());
            }
            foreach (['messages', 'conversation_sequences', 'outbox_events'] as $table) { self::assertSame(0, $this->rowCount($table)); }
            $this->write('800', '1', 'existing');
            try {
                (new MessageArchiveImporter($this->pdo))->import(...$files);
                self::fail('An archive must not overwrite current messages');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('empty message tables', $e->getMessage());
            }
            self::assertSame(1, $this->rowCount('messages'));
            self::assertSame('800', $this->repository()->listPage('1', 10, null)[0]['message_id']);
        } finally {
            foreach ($files as $file) { unlink($file); }
        }
    }

    public function testPublishedArchiveEventsAreNotRequeued(): void
    {
        $files = $this->archiveFiles();
        try {
            $event = json_decode((string)file_get_contents($files[2]), true, flags: JSON_THROW_ON_ERROR);
            $event['status'] = ['$numberInt' => '1'];
            $event['published_at'] = $event['created_at'];
            file_put_contents($files[2], json_encode($event, JSON_THROW_ON_ERROR));
            (new MessageArchiveImporter($this->pdo))->import(...$files);
            self::assertSame(1, (int)$this->pdo->query('SELECT status FROM outbox_events')->fetchColumn());
            self::assertCount(0, (new OutboxRepository($this->pdo, orderingColumn: 'message_seq'))->claimPending(10));
        } finally {
            foreach ($files as $file) { unlink($file); }
        }
    }

    /** @return array{string,string,string} */
    private function archiveFiles(): array
    {
        $id = static fn (string $v): array => ['$numberLong' => $v];
        $at = ['$date' => ['$numberLong' => '1793232000123']];
        $documents = [
            ['_id' => $id('900'), 'conversation_id' => $id('1'), 'seq' => $id('1'), 'sender_user_id' => $id('100'),
                'sender_persona_id' => $id('101'), 'sender_identity_mode' => ['$numberInt' => '2'],
                'sender_display_name' => 'Frozen name', 'sender_anonymous_ref' => 'anon_snapshot',
                'sender_scope_type' => 'conversation', 'sender_scope_id' => '1', 'client_message_id' => 'archive',
                'type' => ['$numberInt' => '1'], 'content' => '{"body":"history"}', 'created_at' => $at, 'schema_version' => ['$numberInt' => '1']],
            ['_id' => $id('1'), 'last_seq' => $id('1'), 'updated_at' => $at],
            ['_id' => 'archive-event', 'conversation_id' => $id('1'), 'message_id' => $id('900'), 'message_seq' => $id('1'),
                'payload' => ['message' => ['message_id' => '900', 'conversation_id' => '1', 'seq' => '1', 'type' => ['$numberInt' => '1']]],
                'trace_id' => '', 'event_type' => 'message.created.v1', 'status' => ['$numberInt' => '2'],
                'available_at' => $at, 'created_at' => $at, 'attempt_count' => ['$numberInt' => '3']],
        ];
        $files = [];
        foreach ($documents as $document) {
            $file = tempnam(sys_get_temp_dir(), 'message-archive-');
            self::assertIsString($file);
            file_put_contents($file, json_encode($document, JSON_THROW_ON_ERROR) . "\n");
            $files[] = $file;
        }
        return [$files[0], $files[1], $files[2]];
    }

    private function connect(): PDO
    {
        return PostgresConnectionFactory::fromEnvironment(['PG_SCHEMA' => 'message_db']);
    }

    public function testOutboxCannotReferenceTwoDifferentValidMessages(): void
    {
        $this->write('900', '1', 'one');
        $this->write('800', '2', 'two');
        $this->pdo->exec('DELETE FROM outbox_events WHERE id = 800');
        try {
            $this->pdo->exec('UPDATE outbox_events SET message_id = 800 WHERE id = 900');
            self::fail('Independent valid message references must still describe the same message');
        } catch (PDOException $exception) {
            self::assertSame('23503', $exception->getCode());
        }
    }

    public function testConcurrentMessageTimeIsSampledAfterTheSequenceLockWait(): void
    {
        $this->write('900', '1', 'first');
        $this->pdo->beginTransaction();
        $this->pdo->exec('UPDATE conversation_sequences SET last_seq = last_seq WHERE conversation_id = 1');
        $worker = proc_open([PHP_BINARY, __DIR__ . '/../Support/concurrent_message_sender.php', 'after-lock', 'signal-clock'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($worker);
        try {
            fwrite($pipes[0], '1');
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 10);
            self::assertSame("clock-sampled\n", fgets($pipes[1]));
            usleep(30_000);
            $this->pdo->exec('UPDATE messages SET created_at = clock_timestamp() WHERE seq = 1');
            $this->pdo->commit();
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($worker), $output . $error);
            $rows = $this->repository()->listAfter('1', '0', 10);
            self::assertSame(['1', '2'], array_column($rows, 'seq'));
            self::assertGreaterThanOrEqual($rows[0]['created_at'], $rows[1]['created_at']);
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if (is_resource($worker)) {
                proc_terminate($worker);
                proc_close($worker);
            }
        }
    }

    public function testReconciliationRepairsSummaryWithoutRegressingTimeOrSequence(): void
    {
        $this->write('900', '1', 'one');
        $this->write('800', '1', 'two');
        $chatPdo = PostgresConnectionFactory::fromEnvironment(['PG_SCHEMA' => 'chat_db']);
        $chatPdo->beginTransaction();
        try {
            $chatPdo->exec("INSERT INTO conversations (id, created_at, updated_at) VALUES (1, '2026-10-29 00:00:00+00', '2026-10-29 00:00:00+00')");
            $chat = new \Services\ChatService\Infrastructure\PdoChatRepository($chatPdo);
            $reconciler = new \Services\ChatService\Infrastructure\MessageSummaryReconciler($this->pdo, $chat);
            self::assertSame(1, $reconciler->runOnce());
            $row = $chat->findById('1');
            self::assertSame('2', (string)$row['last_message_seq']);
            self::assertSame('800', (string)$row['last_message_id']);
            $chat->applyMessageSummary('1', '700', '3', '2026-10-28 00:00:00.000');
            self::assertSame(1, $reconciler->runOnce());
            $row = $chat->findById('1');
            self::assertSame('3', (string)$row['last_message_seq']);
            self::assertSame('700', (string)$row['last_message_id']);
            self::assertSame('2026-10-29 00:00:00.123', (new \DateTimeImmutable($row['last_message_at']))->format('Y-m-d H:i:s.v'));
        } finally {
            $chatPdo->rollBack();
        }
    }

    private function manager(): PostgresMessageTransactionManager { return new PostgresMessageTransactionManager($this->pdo); }
    private function repository(): PostgresMessageRepository { return new PostgresMessageRepository($this->pdo); }
    private function app(): MessageApplicationService
    {
        $chat = new class implements ChatMemberSnapshotClient {
            public function getConversationMemberSnapshot(string $trustedUserId, string $conversationId, ?OperationBudget $budget = null): array
            {
                return ['allowed' => true, 'denial_reason' => '', 'member' => ['user_id' => $trustedUserId, 'persona_id' => '101', 'identity_mode' => 1,
                    'display_name_snapshot' => 'Snapshot', 'avatar_key_snapshot' => null]];
            }
        };
        return new MessageApplicationService($this->repository(), $this->manager(), $chat,
            projector: new AnonymousAuthorProjector(new AnonymousReferenceFactory(str_repeat('k', 32))));
    }

    private function rowCount(string $table): int { return (int)$this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(); }

    private function write(string $id, string $conversation, string $client, string $content = '{"body":"hello"}'): void
    {
        $this->manager()->transaction(fn ($tx) => $this->writeIn($tx, $id, $conversation, $client, $content), new OperationBudget(3000));
    }

    private function writeIn(MessageWriteTransactionInterface $tx, string $id, string $conversation, string $client, string $content = '{"body":"hello"}'): void
    {
        $now = '2026-10-29 00:00:00.123';
        $seq = $tx->nextSequence($conversation, $now);
        $tx->insert(['message_id' => $id, 'conversation_id' => $conversation, 'seq' => $seq, 'sender_user_id' => '100',
            'sender_persona_id' => '101', 'sender_identity_mode' => 1, 'client_message_id' => $client, 'type' => 1,
            'content' => $content, 'created_at' => $now]);
        $tx->recordMessageCreated('event-' . $id, $conversation, $id, $seq, ['message' => ['seq' => $seq]], '', $now);
    }

    private function concurrent(bool $sameClient): void
    {
        $workers = [];
        for ($i = 0; $i < 2; $i++) {
            $worker = proc_open([PHP_BINARY, __DIR__ . '/../Support/concurrent_message_sender.php', $sameClient ? 'same' : 'client-' . $i],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            self::assertIsResource($worker);
            $workers[] = [$worker, $pipes];
        }
        foreach ($workers as [$worker, $pipes]) { fwrite($pipes[0], '1'); fclose($pipes[0]); }
        foreach ($workers as [$worker, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($worker), $output . $error);
        }
    }
}
