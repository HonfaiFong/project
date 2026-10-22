<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contracts\Errors\ErrorCode;
use MongoDB\BSON\Int64;
use MongoDB\Client;
use MongoDB\Database;
use Packages\Database\MongoConnectionFactory;
use Packages\GrpcCommon\OperationBudget;
use Packages\Identity\AnonymousAuthorProjector;
use Packages\Identity\AnonymousReferenceFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Services\MessageService\Application\MessageApplicationService;
use Services\MessageService\Domain\ChatMemberSnapshotClient;
use Services\MessageService\Domain\Exception\MessageException;
use Services\MessageService\Domain\IdGenerator;
use Services\MessageService\Domain\Repository\MessageWriteTransactionInterface;
use Services\MessageService\Infrastructure\Mongo\MongoMessageRepository;
use Services\MessageService\Infrastructure\Mongo\MongoMessageTransactionManager;
use Services\MessageService\Infrastructure\Mongo\NativeMongoTransactionSession;
use Tests\Support\CommitAcknowledgementLostSession;
use Tests\Support\LabeledMongoException;

/**
 * Real replica-set persistence with injected transient errors and lost commit acknowledgements.
 * Commit retry control flow is also covered by MongoMessageTransactionManagerTest.
 * These tests do not trigger a real multi-node primary election.
 */
final class MongoTransactionFailoverTest extends TestCase
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

    public function testReplicaSetReportsWritablePrimaryWithMajorityWriteConcern(): void
    {
        $hello = $this->database->command(['hello' => 1])->toArray();
        self::assertNotEmpty($hello);
        $status = (array)$hello[0];
        self::assertTrue($status['isWritablePrimary'] ?? false, 'MongoDB connection must be connected to a writable primary');
        self::assertArrayHasKey('setName', $status, 'MongoDB must be running with replicaSet enabled');
    }

    public function testTransientFailoverErrorTriggersTransactionRetryAndSucceeds(): void
    {
        $manager = new MongoMessageTransactionManager($this->client, $this->databaseName);
        $attempts = 0;

        $result = $manager->transaction(function (MessageWriteTransactionInterface $tx) use (&$attempts): string {
            $attempts++;
            $now = '2026-10-21 12:00:00.000';
            $seq = $tx->nextSequence('70001', $now);
            $tx->insert([
                'message_id' => '90001',
                'conversation_id' => '70001',
                'seq' => $seq,
                'sender_user_id' => '100',
                'sender_persona_id' => '200',
                'sender_identity_mode' => 1,
                'client_message_id' => 'client-tx-retry',
                'type' => 1,
                'content' => '{"body":"failover retry success"}',
                'created_at' => $now,
            ]);
            $tx->recordMessageCreated('event-msg-failover-1', '70001', '90001', $seq, ['message' => ['message_id' => '90001']], '', $now);
            if ($attempts === 1) {
                // Fail after writes so rollback of message, sequence and outbox is exercised.
                throw new LabeledMongoException('TransientTransactionError');
            }

            return 'ok';
        }, new OperationBudget(5000));

        self::assertSame('ok', $result);
        self::assertSame(2, $attempts, 'TransientTransactionError must cause the transaction manager to retry');

        $repo = new MongoMessageRepository($this->client, $this->databaseName);
        $msg = $repo->findBySenderClientId('100', 'client-tx-retry');
        self::assertNotNull($msg);
        self::assertSame('90001', $msg['message_id']);
        self::assertSame('1', (string)$msg['seq']);
        self::assertSame(1, $this->database->selectCollection('outbox_events')->countDocuments());
    }

    public function testExpiredBudgetReturnsRpcTimeoutBeforeExecutingOperation(): void
    {
        // An expired request must fail before beginning the transaction.
        $budget = new OperationBudget(1);
        usleep(2000); // 确保预算已耗尽

        $manager = new MongoMessageTransactionManager($this->client, $this->databaseName);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(ErrorCode::RPC_TIMEOUT->value);
        $manager->transaction(function (MessageWriteTransactionInterface $tx): void {
            self::fail('An expired request must not execute the operation');
        }, $budget);
    }

    public function testIdempotentRecoveryAfterFailoverReturnsSameMessageWithoutDuplicateSequence(): void
    {
        $chat = $this->createMock(ChatMemberSnapshotClient::class);
        $chat->method('getConversationMemberSnapshot')->willReturn([
            'allowed' => true,
            'denial_reason' => '',
            'member' => [
                'user_id' => '100',
                'persona_id' => '200',
                'identity_mode' => 1,
                'display_name_snapshot' => 'Tester',
                'avatar_key_snapshot' => 'avatar:key',
                'scope_type' => '',
                'scope_id' => '',
                'anonymous_ref' => '',
            ],
        ]);

        $ids = new class implements IdGenerator {
            private int $counter = 5001;

            public function nextId(): string
            {
                return (string)$this->counter++;
            }
        };

        $repo = new MongoMessageRepository($this->client, $this->databaseName);
        $txManager = new MongoMessageTransactionManager(
            $this->client,
            $this->databaseName,
            fn () => new CommitAcknowledgementLostSession(new NativeMongoTransactionSession($this->client, $this->databaseName)),
        );
        $projector = new AnonymousAuthorProjector(new AnonymousReferenceFactory(str_repeat('f', 32)));

        $app = new MessageApplicationService(
            repository: $repo,
            transactions: $txManager,
            chat: $chat,
            ids: $ids,
            projector: $projector,
            clock: static fn (): string => '2026-10-21 12:00:00.000',
        );

        $payload = [
            'client_message_id' => 'client-idempotent-failover-1',
            'conversation_id' => '70002',
            'type' => 1,
            'content' => '{"text":"hello idempotent world"}',
        ];

        // The database commits, but every acknowledgement is lost until the request times out.
        try {
            $app->sendMessage('100', $payload, new OperationBudget(2000));
            self::fail('Expected an unknown commit result');
        } catch (RuntimeException $exception) {
            self::assertSame(ErrorCode::RPC_TIMEOUT->value, $exception->getCode());
            self::assertStringContainsString('commit result is unknown', $exception->getMessage());
        }
        $persisted = $repo->findBySenderClientId('100', $payload['client_message_id']);
        self::assertNotNull($persisted);

        // 客户端在切主/网络闪断重连后重试同一 client_message_id
        $second = $app->sendMessage('100', $payload);
        self::assertSame($persisted['message_id'], (string)$second->getMessageId(), 'Client retry must return the committed message');
        self::assertSame('1', (string)$second->getSeq(), 'Client retry must NOT increment sequence number');

        // 数据库断言：仅有一条消息、序号仅推进到 1、Outbox 仅有一条事件
        self::assertSame(1, $this->database->selectCollection('messages')->countDocuments(['conversation_id' => new Int64('70002')]));
        self::assertSame(1, $this->database->selectCollection('outbox_events')->countDocuments(['conversation_id' => new Int64('70002')]));
        self::assertSame('1', $repo->latestSequence('70002'));
    }

    public function testIdempotentRetryRejectsTamperedPayload(): void
    {
        $chat = $this->createMock(ChatMemberSnapshotClient::class);
        $chat->method('getConversationMemberSnapshot')->willReturn([
            'allowed' => true,
            'denial_reason' => '',
            'member' => [
                'user_id' => '200',
                'persona_id' => '300',
                'identity_mode' => 1,
                'display_name_snapshot' => 'Tamper Test',
                'avatar_key_snapshot' => 'avatar:key',
                'scope_type' => '',
                'scope_id' => '',
                'anonymous_ref' => '',
            ],
        ]);

        $ids = new class implements IdGenerator {
            private int $counter = 9001;

            public function nextId(): string
            {
                return (string)$this->counter++;
            }
        };

        $repo = new MongoMessageRepository($this->client, $this->databaseName);
        $txManager = new MongoMessageTransactionManager($this->client, $this->databaseName);
        $projector = new AnonymousAuthorProjector(new AnonymousReferenceFactory(str_repeat('f', 32)));

        $app = new MessageApplicationService(
            repository: $repo,
            transactions: $txManager,
            chat: $chat,
            ids: $ids,
            projector: $projector,
            clock: static fn (): string => '2026-10-21 12:00:00.000',
        );

        $payload = [
            'client_message_id' => 'client-tamper-check',
            'conversation_id' => '70003',
            'type' => 1,
            'content' => '{"text":"original content"}',
        ];

        $app->sendMessage('200', $payload);

        $tamperedPayload = $payload;
        $tamperedPayload['content'] = '{"text":"tampered content"}';

        $this->expectException(MessageException::class);
        $this->expectExceptionCode(ErrorCode::MESSAGE_DUPLICATE_CLIENT_ID->value);
        $app->sendMessage('200', $tamperedPayload);
    }

    private function migrate(): void
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/scripts/migrate_mongodb.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        self::assertSame(0, $exitCode, (string)$stdout . (string)$stderr);
    }
}
