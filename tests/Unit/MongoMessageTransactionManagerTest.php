<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Errors\ErrorCode;
use MongoDB\Client;
use Packages\GrpcCommon\OperationBudget;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Services\MessageService\Domain\Repository\MessageWriteTransactionInterface;
use Services\MessageService\Infrastructure\Mongo\MongoMessageTransactionManager;
use Services\MessageService\Infrastructure\Mongo\MongoTransactionSession;
use Tests\Support\LabeledMongoException;

final class MongoMessageTransactionManagerTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (['MONGODB_RPC_MAX_TRANSACTION_ATTEMPTS' => '3', 'MONGODB_RPC_RETRY_DELAY_MS' => '0',
            'MONGODB_RPC_COMMIT_TIMEOUT_MS' => '150', 'MONGODB_RPC_WRITE_TIMEOUT_MS' => '250',
            'MONGODB_RPC_BUDGET_RESERVE_MS' => '100'] as $name => $value) {
            $this->environment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testTransientErrorAbortsAndReplaysOperationInANewSession(): void
    {
        $first = $this->createMock(MongoTransactionSession::class);
        $second = $this->createMock(MongoTransactionSession::class);
        foreach ([$first, $second] as $session) {
            $session->expects(self::once())->method('begin')->willReturn($this->createStub(MessageWriteTransactionInterface::class));
            $session->expects(self::once())->method('end');
        }
        $first->expects(self::once())->method('abort');
        $first->expects(self::never())->method('commit');
        $second->expects(self::never())->method('abort');
        $second->expects(self::once())->method('commit');
        $sessions = [$first, $second];
        $manager = new MongoMessageTransactionManager($this->createStub(Client::class), sessionFactory: static function () use (&$sessions): MongoTransactionSession {
            return array_shift($sessions);
        });
        $attempts = 0;
        $result = $manager->transaction(static function () use (&$attempts): string {
            if (++$attempts === 1) {
                throw new RuntimeException('wrapped driver failure', previous: new LabeledMongoException('TransientTransactionError'));
            }
            return 'saved';
        }, new OperationBudget(5000));
        self::assertSame('saved', $result);
        self::assertSame(2, $attempts);
        self::assertSame([], $sessions);
    }

    public function testUnknownCommitRetriesOnlyCommit(): void
    {
        $session = $this->createMock(MongoTransactionSession::class);
        $session->expects(self::once())->method('begin')->willReturn($this->createStub(MessageWriteTransactionInterface::class));
        $session->expects(self::never())->method('abort');
        $session->expects(self::once())->method('end');
        $commits = 0;
        $session->expects(self::exactly(2))->method('commit')->willReturnCallback(static function () use (&$commits): void {
            if (++$commits === 1) {
                throw new LabeledMongoException('UnknownTransactionCommitResult');
            }
        });
        $manager = new MongoMessageTransactionManager($this->createStub(Client::class), sessionFactory: static fn () => $session);
        $calls = 0;
        self::assertSame('saved', $manager->transaction(static function () use (&$calls): string {
            $calls++;
            return 'saved';
        }, new OperationBudget(5000)));
        self::assertSame(1, $calls);
    }

    public function testUnknownCommitWithoutRetryBudgetReturnsTimeoutWithoutAbortingOrReplaying(): void
    {
        putenv('MONGODB_RPC_RETRY_DELAY_MS=1000');
        $session = $this->createMock(MongoTransactionSession::class);
        $session->expects(self::once())->method('begin')->willReturn($this->createStub(MessageWriteTransactionInterface::class));
        $session->expects(self::once())->method('commit')->willThrowException(new LabeledMongoException('UnknownTransactionCommitResult'));
        $session->expects(self::never())->method('abort');
        $session->expects(self::once())->method('end');
        $manager = new MongoMessageTransactionManager($this->createStub(Client::class), sessionFactory: static fn () => $session);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(ErrorCode::RPC_TIMEOUT->value);
        $manager->transaction(static fn () => 'saved', new OperationBudget(1000));
    }

    public function testTransientRetriesStopAtConfiguredAttemptLimit(): void
    {
        $session = $this->createMock(MongoTransactionSession::class);
        $session->expects(self::exactly(3))->method('begin')->willReturn($this->createStub(MessageWriteTransactionInterface::class));
        $session->expects(self::exactly(3))->method('abort');
        $session->expects(self::exactly(3))->method('end');
        $session->expects(self::never())->method('commit');
        $manager = new MongoMessageTransactionManager($this->createStub(Client::class), sessionFactory: static fn () => $session);
        $this->expectException(LabeledMongoException::class);
        $manager->transaction(static fn () => throw new LabeledMongoException('TransientTransactionError'), new OperationBudget(5000));
    }

    public function testNonRetryableFailurePreservesOriginalEvenIfAbortFails(): void
    {
        $session = $this->createMock(MongoTransactionSession::class);
        $session->expects(self::once())->method('begin')->willReturn($this->createStub(MessageWriteTransactionInterface::class));
        $session->expects(self::once())->method('abort')->willThrowException(new RuntimeException('abort failed'));
        $session->expects(self::once())->method('end');
        $session->expects(self::never())->method('commit');
        $manager = new MongoMessageTransactionManager($this->createStub(Client::class), sessionFactory: static fn () => $session);
        $this->expectExceptionMessage('operation failed');
        $manager->transaction(static fn () => throw new RuntimeException('operation failed'), new OperationBudget(5000));
    }

    public function testInvalidConfigurationDoesNotCreateASessionOrExecuteOperation(): void
    {
        putenv('MONGODB_RPC_COMMIT_TIMEOUT_MS=invalid');
        $manager = new MongoMessageTransactionManager($this->createStub(Client::class), sessionFactory: static fn () => throw new \LogicException('session must not be created'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MONGODB_RPC_COMMIT_TIMEOUT_MS is outside its supported range');
        $manager->transaction(static fn () => throw new \LogicException('operation must not run'));
    }

    public function testExpiredBudgetDoesNotCreateASessionOrExecuteOperation(): void
    {
        $budget = new OperationBudget(1);
        usleep(2000);
        $manager = new MongoMessageTransactionManager($this->createStub(Client::class), sessionFactory: static fn () => throw new \LogicException('session must not be created'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(ErrorCode::RPC_TIMEOUT->value);
        $manager->transaction(static fn () => throw new \LogicException('operation must not run'), $budget);
    }
}
