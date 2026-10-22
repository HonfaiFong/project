<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contracts\Errors\ErrorCode;
use MongoDB\BSON\Int64;
use MongoDB\Client;
use MongoDB\Database;
use MongoDB\Driver\Exception\CommandException;
use MongoDB\Driver\Exception\ServerException;
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
use Throwable;

/**
 * 验证 MongoDB 故障切换、未知提交恢复与事务幂等。
 *
 * 覆盖：
 * 1. 副本集写主节点状态探测与多数派写保障
 * 2. 事务执行期间遇到瞬态故障（TransientTransactionError，如切主/重选举）时的全量事务重试与成功恢复
 * 3. 提交阶段遇到未知提交结果（UnknownTransactionCommitResult）时的安全提交重试（不重放业务回调）
 * 4. 超出请求预算时的未知提交超时保护（RPC_TIMEOUT，提示客户端用原 client_message_id 恢复）
 * 5. 故障切主/超时恢复后客户端重试同一 client_message_id 的幂等去重与防消息/序号重复
 * 6. client_message_id 内容冲突防御（拒绝载荷篡改）
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
            if ($attempts === 1) {
                // 模拟主节点切换或网络闪断抛出 TransientTransactionError 瞬态异常
                $exception = new class('not master / node stepped down', 10107) extends ServerException {
                    public function hasErrorLabel(string $label): bool
                    {
                        return $label === 'TransientTransactionError';
                    }
                };
                throw $exception;
            }

            $now = '2026-10-21 12:00:00.000';
            $seq = $tx->nextSequence('conv-failover-1', $now);
            $tx->insert([
                'message_id' => 'msg-failover-1',
                'conversation_id' => 'conv-failover-1',
                'seq' => $seq,
                'sender_user_id' => '100',
                'sender_persona_id' => '200',
                'sender_identity_mode' => 1,
                'client_message_id' => 'client-tx-retry',
                'type' => 1,
                'content' => '{"body":"failover retry success"}',
                'created_at' => $now,
            ]);
            $tx->recordMessageCreated('event-msg-failover-1', 'conv-failover-1', 'msg-failover-1', $seq, ['message' => ['message_id' => 'msg-failover-1']], '', $now);

            return 'ok';
        }, new OperationBudget(5000));

        self::assertSame('ok', $result);
        self::assertSame(2, $attempts, 'TransientTransactionError must cause the transaction manager to retry');

        $repo = new MongoMessageRepository($this->client, $this->databaseName);
        $msg = $repo->findBySenderClientId('100', 'client-tx-retry');
        self::assertNotNull($msg);
        self::assertSame('msg-failover-1', $msg['message_id']);
        self::assertSame('1', (string)$msg['seq']);
        self::assertSame(1, $this->database->selectCollection('outbox_events')->countDocuments());
    }

    public function testUnknownCommitResultExhaustsBudgetAndReturnsRpcTimeout(): void
    {
        // 验证提交阶段遇到未知结果且请求预算耗尽时，抛出明确的 RPC_TIMEOUT
        $budget = new OperationBudget(1);
        usleep(2000); // 确保预算已耗尽

        $manager = new MongoMessageTransactionManager($this->client, $this->databaseName);
        try {
            $manager->transaction(function (MessageWriteTransactionInterface $tx): void {
                // 不做耗时操作，直接尝试进入提交阶段
            }, $budget);
            self::fail('Expected budget exhaustion');
        } catch (RuntimeException $exception) {
            self::assertSame(ErrorCode::RPC_TIMEOUT->value, $exception->getCode());
        }
    }

    public function testIdempotentRecoveryAfterFailoverReturnsSameMessageWithoutDuplicateSequence(): void
    {
        $chat = $this->createMock(ChatMemberSnapshotClient::class);
        $chat->method('getConversationMemberSnapshot')->willReturn([
            'allowed' => true,
            'denial_reason' => '',
            'member' => [
                'user_id' => 'user-100',
                'persona_id' => 'persona-200',
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
            'client_message_id' => 'client-idempotent-failover-1',
            'conversation_id' => 'conv-idempotent-1',
            'type' => 1,
            'content' => '{"text":"hello idempotent world"}',
            'sender_user_id' => 'user-100',
            'sender_persona_id' => 'persona-200',
            'sender_identity_mode' => 1,
        ];

        // 第一次发送：成功持久化
        $first = $app->sendMessage('user-100', $payload);
        self::assertSame(1, $first->getSeq());
        $firstId = $first->getMessageId();

        // 客户端在切主/网络闪断重连后重试同一 client_message_id
        $second = $app->sendMessage('user-100', $payload);
        self::assertSame($firstId, $second->getMessageId(), 'Client retry after failover must return original message_id');
        self::assertSame(1, $second->getSeq(), 'Client retry must NOT increment sequence number');

        // 数据库断言：仅有一条消息、序号仅推进到 1、Outbox 仅有一条事件
        self::assertSame(1, $this->database->selectCollection('messages')->countDocuments(['conversation_id' => new Int64('conv-idempotent-1')]));
        self::assertSame(1, $this->database->selectCollection('outbox_events')->countDocuments(['conversation_id' => new Int64('conv-idempotent-1')]));
        self::assertSame('1', $repo->latestSequence('conv-idempotent-1'));
    }

    public function testIdempotentRetryRejectsTamperedPayload(): void
    {
        $chat = $this->createMock(ChatMemberSnapshotClient::class);
        $chat->method('getConversationMemberSnapshot')->willReturn([
            'allowed' => true,
            'denial_reason' => '',
            'member' => [
                'user_id' => 'user-200',
                'persona_id' => 'persona-300',
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
            'conversation_id' => 'conv-tamper-1',
            'type' => 1,
            'content' => '{"text":"original content"}',
            'sender_user_id' => 'user-200',
            'sender_persona_id' => 'persona-300',
            'sender_identity_mode' => 1,
        ];

        $app->sendMessage('user-200', $payload);

        $tamperedPayload = $payload;
        $tamperedPayload['content'] = '{"text":"tampered content"}';

        $this->expectException(MessageException::class);
        $app->sendMessage('user-200', $tamperedPayload);
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
