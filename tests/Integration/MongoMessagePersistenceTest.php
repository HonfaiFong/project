<?php

declare(strict_types=1);

namespace Tests\Integration;

use MongoDB\BSON\Int64;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Database;
use Packages\Database\MongoConnectionFactory;
use Packages\GrpcCommon\OperationBudget;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Services\MessageService\Domain\Repository\MessageWriteTransactionInterface;
use Services\MessageService\Infrastructure\Mongo\MongoMessageRepository;
use Services\MessageService\Infrastructure\Mongo\MongoMessageTransactionManager;
use Services\MessageService\Infrastructure\Mongo\MongoOutboxRelayStore;

/** Run only against a disposable test_* database, never a developer's message data. */
final class MongoMessagePersistenceTest extends TestCase
{
    private Client $client;
    private Database $database;
    private string $databaseName;

    protected function setUp(): void
    {
        if (getenv('DATABASE_INTEGRATION') !== '1') {
            self::markTestSkipped('DATABASE_INTEGRATION=1 is required');
        }
        self::assertTrue(extension_loaded('mongodb'), 'MongoDB extension is required');
        $this->databaseName = (string)getenv('MONGODB_DATABASE');
        self::assertStringStartsWith('test_', $this->databaseName, 'Use a disposable test_* database');
        $this->client = MongoConnectionFactory::fromEnvironment();
        $this->database = $this->client->selectDatabase($this->databaseName);
        $this->migrate();
        foreach (['messages', 'conversation_sequences', 'outbox_events'] as $collection) {
            $this->database->selectCollection($collection)->deleteMany([]);
        }
    }

    public function testPartialMigrationAndRepeatedRunReleaseLeaseAndUpgradeValidator(): void
    {
        // All collections already exist, but the ledger was never written: the
        // NamespaceExists recovery path must finish instead of throwing code 48.
        $this->database->selectCollection('schema_migrations')->deleteMany([]);
        $this->migrate();
        $this->migrate();
        self::assertSame(3, $this->database->selectCollection('schema_migrations')->countDocuments());
        self::assertSame(0, $this->database->selectCollection('schema_migration_locks')->countDocuments());
        $this->write('101', '1', 'first');
        $claims = (new MongoOutboxRelayStore($this->client, $this->databaseName))->claimPending(1);
        self::assertCount(1, $claims, 'The installed validator must accept claimed_at as a BSON date');
    }

    public function testMessageSequenceAndOutboxCommitTogetherAndRollbackTogether(): void
    {
        $this->write('101', '1', 'first');
        $this->write('102', '1', 'second');
        $repo = new MongoMessageRepository($this->client, $this->databaseName);
        self::assertSame(['1', '2'], array_column($repo->listAfter('1', '0', 10), 'seq'));
        self::assertSame('2', $repo->latestSequence('1'));
        self::assertSame(2, $this->database->selectCollection('outbox_events')->countDocuments());
        try {
            $this->manager()->transaction(function (MessageWriteTransactionInterface $tx): void {
                $this->writeIn($tx, '103', '1', 'aborted');
                throw new RuntimeException('abort transaction');
            }, new OperationBudget(3000));
        } catch (RuntimeException $exception) {
            self::assertSame('abort transaction', $exception->getMessage());
        }
        self::assertSame(2, $this->database->selectCollection('messages')->countDocuments());
        self::assertSame('2', (string)$this->database->selectCollection('conversation_sequences')->findOne(['_id' => new Int64('1')])['last_seq']);
        self::assertSame(2, $this->database->selectCollection('outbox_events')->countDocuments());
    }

    public function testUpgradeFromRecordedV2PreservesDataAndRemovesLegacyPagingIndex(): void
    {
        $this->write('901', '71', 'before-v3-upgrade');
        $ledger = $this->database->selectCollection('schema_migrations');
        $ledger->deleteOne(['_id' => '20261020000300_drop_message_id_paging_index']);
        $this->database->selectCollection('messages')->createIndex(
            ['conversation_id' => 1, '_id' => -1], ['name' => 'idx_conversation_message_id_desc'],
        );
        $previous = iterator_to_array($ledger->find([], ['sort' => ['_id' => 1]]), false);
        self::assertCount(2, $previous);

        $this->migrate();
        $this->migrate();

        foreach ($previous as $migration) {
            self::assertSame((string)$migration['checksum'], (string)$ledger->findOne(['_id' => $migration['_id']])['checksum']);
        }
        self::assertSame(3, $ledger->countDocuments());
        $indexes = $this->database->command(['listIndexes' => 'messages'], [
            'readConcern' => new \MongoDB\Driver\ReadConcern(),
            'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
        ])->toArray();
        self::assertNotContains('idx_conversation_message_id_desc', array_column($indexes, 'name'));
        $repo = new MongoMessageRepository($this->client, $this->databaseName);
        self::assertSame('901', $repo->findBySenderClientId('100', 'before-v3-upgrade')['message_id']);
        self::assertSame('1', $repo->latestSequence('71'));
        self::assertSame(1, $this->database->selectCollection('outbox_events')->countDocuments());
    }

    public function testConcurrentTransactionsAllocateDistinctConversationSequences(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for concurrent MongoDB transaction coverage');
        }

        $children = [];
        $gates = [];
        foreach ([['101', 'first'], ['102', 'second']] as [$id, $clientId]) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertIsArray($sockets);
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Could not fork a MongoDB transaction worker');
            }
            if ($pid === 0) {
                fclose($sockets[0]);
                fread($sockets[1], 1);
                fclose($sockets[1]);
                try {
                    $client = MongoConnectionFactory::fromEnvironment();
                    $manager = new MongoMessageTransactionManager($client, $this->databaseName);
                    $manager->transaction(
                        fn (MessageWriteTransactionInterface $tx) => $this->writeIn($tx, $id, '77', $clientId),
                        new OperationBudget(5000),
                    );
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }
            fclose($sockets[1]);
            $children[] = $pid;
            $gates[] = $sockets[0];
        }

        foreach ($gates as $gate) {
            fwrite($gate, '1');
            fclose($gate);
        }
        foreach ($children as $pid) {
            $status = 0;
            self::assertSame($pid, pcntl_waitpid($pid, $status));
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }

        $messages = (new MongoMessageRepository($this->client, $this->databaseName))->listAfter('77', '0', 10);
        self::assertSame(['1', '2'], array_column($messages, 'seq'));
        self::assertSame(2, $this->database->selectCollection('outbox_events')->countDocuments());
    }

    public function testHistoryPagesFollowConversationSequenceAndUseItsIndex(): void
    {
        $this->write('900', '1', 'first');
        $this->write('800', '1', 'second');
        $repository = new MongoMessageRepository($this->client, $this->databaseName);

        $firstPage = $repository->listPage('1', 1, null);
        self::assertSame(['2', '1'], array_column($firstPage, 'seq'));
        self::assertSame(['800', '900'], array_column($firstPage, 'message_id'));
        $secondPage = $repository->listPage('1', 1, '2');
        self::assertSame(['1'], array_column($secondPage, 'seq'));
        self::assertSame(['900'], array_column($secondPage, 'message_id'));

        $explain = $this->database->command([
            'explain' => [
                'find' => 'messages',
                'filter' => ['conversation_id' => new Int64('1'), 'seq' => ['$lt' => new Int64('2')]],
                'sort' => ['seq' => -1],
                'limit' => 2,
            ],
            'verbosity' => 'queryPlanner',
        ])->toArray();
        self::assertStringContainsString('uniq_conversation_seq', json_encode($explain, JSON_THROW_ON_ERROR));
    }

    public function testRecordedMigrationValidatorDriftFailsWithoutRepair(): void
    {
        $this->database->command([
            'collMod' => 'outbox_events',
            'validator' => ['$jsonSchema' => ['bsonType' => 'object']],
        ]);

        try {
            [$exitCode, $output] = $this->runMigration();
            self::assertNotSame(0, $exitCode);
            self::assertStringContainsString('validator drift', $output);
        } finally {
            $this->database->selectCollection('schema_migrations')->deleteOne([
                '_id' => '20261020000200_outbox_claimed_at_date',
            ]);
            $this->database->selectCollection('schema_migrations')->deleteOne([
                '_id' => '20261020000300_drop_message_id_paging_index',
            ]);
            $this->migrate();
        }
    }

    public function testRecordedMigrationMissingIndexFailsWithoutRepair(): void
    {
        $this->database->selectCollection('messages')->dropIndex('uniq_conversation_seq');

        try {
            [$exitCode, $output] = $this->runMigration();
            self::assertNotSame(0, $exitCode);
            self::assertStringContainsString('index set drift', $output);
            $metadata = $this->database->command(['listIndexes' => 'messages'], [
                'readConcern' => new \MongoDB\Driver\ReadConcern(),
                'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
            ])->toArray();
            $indexNames = array_column($metadata, 'name');
            self::assertNotContains('uniq_conversation_seq', $indexNames, 'A recorded migration must not silently repair a dropped index');
        } finally {
            $this->database->selectCollection('messages')->createIndex(
                ['conversation_id' => 1, 'seq' => 1],
                ['name' => 'uniq_conversation_seq', 'unique' => true],
            );
        }
    }

    public function testExpiredCallbackCannotCommitWrites(): void
    {
        try {
            $this->manager()->transaction(function (MessageWriteTransactionInterface $tx): void {
                $this->writeIn($tx, '101', '1', 'expired');
                usleep(300_000);
            }, new OperationBudget(300));
            self::fail('An expired transaction must not commit');
        } catch (RuntimeException $exception) {
            self::assertSame(\Contracts\Errors\ErrorCode::RPC_TIMEOUT->value, $exception->getCode());
        }
        self::assertSame(0, $this->database->selectCollection('messages')->countDocuments());
        self::assertSame(0, $this->database->selectCollection('conversation_sequences')->countDocuments());
        self::assertSame(0, $this->database->selectCollection('outbox_events')->countDocuments());
    }

    public function testReadsRejectExhaustedBudgetBeforeDatabaseIo(): void
    {
        $budget = new OperationBudget(1);
        usleep(2000);
        $this->expectExceptionCode(\Contracts\Errors\ErrorCode::RPC_TIMEOUT->value);
        (new MongoMessageRepository($this->client, $this->databaseName))->findBySenderClientId('100', 'first', $budget);
    }

    public function testRuntimeRelayAndAnonymousConnectionsHaveSeparatePrivileges(): void
    {
        $runtimePassword = (string)getenv('MONGODB_TEST_RUNTIME_PASSWORD');
        $relayPassword = (string)getenv('MONGODB_TEST_RELAY_PASSWORD');
        self::assertNotSame('', $runtimePassword, 'Pass the disposable runtime account password');
        self::assertNotSame('', $relayPassword, 'Pass the disposable relay account password');
        $runtime = MongoConnectionFactory::fromEnvironment([
            'MONGODB_USER' => 'message_runtime', 'MONGODB_PASSWORD' => $runtimePassword,
        ])->selectDatabase($this->databaseName);
        $relay = MongoConnectionFactory::fromEnvironment([
            'MONGODB_USER' => 'message_outbox_relay', 'MONGODB_PASSWORD' => $relayPassword,
        ])->selectDatabase($this->databaseName);
        self::assertNull($runtime->selectCollection('messages')->findOne([]));
        self::assertNull($relay->selectCollection('outbox_events')->findOne([]));
        $this->assertUnauthorized(fn () => $runtime->selectCollection('outbox_events')->findOne([]));
        $this->assertUnauthorized(fn () => $relay->selectCollection('messages')->findOne([]));
        $this->assertUnauthorized(fn () => $relay->createCollection('unauthorized_ddl'));
        $anonymous = new Client((string)getenv('MONGODB_URI'));
        $this->assertUnauthorized(fn () => $anonymous->selectCollection($this->databaseName, 'messages')->findOne([]));
    }

    public function testOutboxOrderingAndStaleClaimFencing(): void
    {
        $this->write('101', '1', 'first');
        $this->write('102', '1', 'second');
        $relay = new MongoOutboxRelayStore($this->client, $this->databaseName);
        $first = $relay->claimPending(10);
        self::assertCount(1, $first);
        self::assertSame('event-101', $first[0]->record->eventId);
        $this->database->selectCollection('outbox_events')->updateOne(
            ['_id' => 'event-101'], ['$set' => ['lease_until' => new UTCDateTime(0)]],
        );
        self::assertSame(1, $relay->releaseStaleClaims());
        $replacement = $relay->claimPending(10);
        self::assertCount(1, $replacement);
        self::assertNotSame($first[0]->claimToken, $replacement[0]->claimToken);
        self::assertFalse($relay->markPublished($first[0]));
        self::assertFalse($relay->markFailed($first[0]));
        self::assertTrue($relay->markPublished($replacement[0]));
        $second = $relay->claimPending(10);
        self::assertCount(1, $second);
        self::assertSame('event-102', $second[0]->record->eventId);
    }

    public function testBlockedConversationCannotStarveEventsBeyondScanBudget(): void
    {
        $this->write('101', '1', 'head');
        $this->write('102', '2', 'unrelated');
        $outbox = $this->database->selectCollection('outbox_events');
        $head = $outbox->findOne(['_id' => 'event-101'], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
        $outbox->updateOne(['_id' => 'event-101'], ['$set' => [
            'status' => 2, 'claim_token' => 'busy', 'claimed_at' => new UTCDateTime(),
            'lease_until' => new UTCDateTime((time() + 3600) * 1000),
        ]]);
        $blocked = [];
        for ($seq = 2; $seq <= 2002; $seq++) {
            $event = $head;
            $event['_id'] = sprintf('blocked-%05d', $seq);
            $event['conversation_id'] = new Int64('1');
            $event['message_id'] = new Int64((string)(1000 + $seq));
            $event['message_seq'] = new Int64((string)$seq);
            $blocked[] = $event;
        }
        $outbox->insertMany($blocked);
        $outbox->updateOne(['_id' => 'event-102'], ['$set' => ['available_at' => new UTCDateTime((time() - 1) * 1000)]]);
        $relay = new MongoOutboxRelayStore($this->client, $this->databaseName);
        $claims = [];
        for ($tick = 0; $tick < 4 && $claims === []; $tick++) {
            $claims = $relay->claimPending(1);
        }
        self::assertCount(1, $claims);
        self::assertSame('event-102', $claims[0]->record->eventId);
    }

    private function manager(): MongoMessageTransactionManager
    {
        return new MongoMessageTransactionManager($this->client, $this->databaseName);
    }

    private function assertUnauthorized(callable $operation): void
    {
        try {
            $operation();
            self::fail('The database must reject this operation');
        } catch (\MongoDB\Driver\Exception\CommandException $exception) {
            self::assertSame(13, $exception->getCode());
        }
    }

    private function write(string $id, string $conversation, string $clientId): void
    {
        $this->manager()->transaction(fn (MessageWriteTransactionInterface $tx) => $this->writeIn($tx, $id, $conversation, $clientId), new OperationBudget(3000));
    }

    private function writeIn(MessageWriteTransactionInterface $tx, string $id, string $conversation, string $clientId): void
    {
        $now = '2020-01-01 00:00:00.123';
        $seq = $tx->nextSequence($conversation, $now);
        $tx->insert([
            'message_id' => $id, 'conversation_id' => $conversation, 'seq' => $seq,
            'sender_user_id' => '100', 'sender_persona_id' => '200', 'sender_identity_mode' => 1,
            'client_message_id' => $clientId, 'type' => 1, 'content' => '{"body":"hello"}', 'created_at' => $now,
        ]);
        $tx->recordMessageCreated('event-' . $id, $conversation, $id, $seq, ['message' => ['message_id' => $id]], '', $now);
    }

    private function migrate(): void
    {
        [$exitCode, $output] = $this->runMigration();
        self::assertSame(0, $exitCode, $output);
    }

    /** @return array{0:int,1:string} */
    private function runMigration(): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/scripts/migrate_mongodb.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string)$stdout . (string)$stderr];
    }
}
