<?php

declare(strict_types=1);

namespace Tests\Unit;

use Packages\IdGenerator\WorkerId;
use Packages\IdGenerator\Snowflake;
use Redis;
use RedisException;
use RuntimeException;
use Tests\TestCase;

final class WorkerIdTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $previousEnv = [];

    /** @var list<int> */
    private array $resolvedIds = [];

    protected function setUp(): void
    {
        foreach (['WORKER_ID', 'WORKER_ID_LEASE_TTL_SECONDS', 'WORKER_ID_LEASE_NAMESPACE'] as $name) {
            $previous = getenv($name);
            $this->previousEnv[$name] = $previous === false ? null : (string)$previous;
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->resolvedIds as $workerId) {
            WorkerId::release($workerId);
        }

        foreach ($this->previousEnv as $name => $value) {
            if ($value !== null) {
                putenv($name . '=' . $value);
            } else {
                putenv($name);
            }
        }
    }

    public function testFailsFastWhenWorkerIdEnvIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WORKER_ID');

        WorkerId::resolve();
    }

    public function testFailsFastWhenWorkerIdEnvIsOutOfRange(): void
    {
        putenv('WORKER_ID=32');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('between 0 and 31');

        WorkerId::resolve();
    }

    public function testResolvesToTenBitRangeAndKeepsServiceSlot(): void
    {
        putenv('WORKER_ID=7');
        $redis = $this->redisWithFreeSlots();

        $workerId = WorkerId::resolve($redis);
        $this->resolvedIds[] = $workerId;

        $this->assertSame(7 * WorkerId::PROCESS_SLOTS, $workerId);
        $this->assertGreaterThanOrEqual(7 * WorkerId::PROCESS_SLOTS, $workerId);
        $this->assertLessThanOrEqual(7 * WorkerId::PROCESS_SLOTS + WorkerId::PROCESS_SLOTS - 1, $workerId);
        $this->assertLessThanOrEqual(1023, $workerId);
    }

    public function testConcurrentAllocationsUseDifferentProcessSlots(): void
    {
        putenv('WORKER_ID=3');
        $keys = [];
        $set = static function (string $key, string $token, array $options) use (&$keys): bool {
            if (isset($keys[$key])) {
                return false;
            }
            $keys[$key] = $token;

            return true;
        };

        $first = $this->redisWithSetCallback($set);
        $second = $this->redisWithSetCallback($set);

        $firstId = WorkerId::resolve($first);
        $this->resolvedIds[] = $firstId;
        $secondId = WorkerId::resolve($second);
        $this->resolvedIds[] = $secondId;

        $this->assertSame(3 * WorkerId::PROCESS_SLOTS, $firstId);
        $this->assertSame($firstId + 1, $secondId);
        $this->assertCount(2, $keys);
    }

    public function testFailsWhenAllProcessSlotsAreOccupied(): void
    {
        putenv('WORKER_ID=3');
        $redis = $this->redisWithSetCallback(static fn (string $key, string $token, array $options): bool => false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No free Worker ID lease');

        WorkerId::resolve($redis);
    }

    public function testAnExpiredSlotCanBeReclaimedByAReplacementWorker(): void
    {
        putenv('WORKER_ID=3');
        $keys = [];
        $redis = $this->redisWithSetCallback(static function (string $key, string $token, array $options) use (&$keys): bool {
            if (isset($keys[$key])) {
                return false;
            }
            $keys[$key] = $token;

            return true;
        });

        $firstId = WorkerId::resolve($redis);
        $this->resolvedIds[] = $firstId;
        $this->assertSame(96, $firstId);

        // Simulate Redis expiring the key after the first process stops
        // renewing. A replacement may now atomically claim the same slot.
        unset($keys['social-backend:worker-id:3:0']);
        WorkerId::release($firstId);
        $this->resolvedIds = [];

        $replacementId = WorkerId::resolve($redis);
        $this->resolvedIds[] = $replacementId;

        $this->assertSame($firstId, $replacementId);
    }

    public function testRedisFailureFailsClosedDuringAllocation(): void
    {
        putenv('WORKER_ID=3');
        $redis = $this->createMock(Redis::class);
        $redis->method('set')->willThrowException(new RedisException('connection lost'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('acquisition failed');

        WorkerId::resolve($redis);
    }

    public function testLeaseLossPreventsFurtherUseAndCannotBeRenewed(): void
    {
        putenv('WORKER_ID=3');
        $redis = $this->createMock(Redis::class);
        $redis->method('set')->willReturn(true);
        $redis->method('eval')->willReturn(0);
        $lease = WorkerId::acquire($redis);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('lease 96 was lost');
        $lease->renew();
    }

    public function testSnowflakeRefusesToGenerateAfterLeaseOwnershipIsLost(): void
    {
        putenv('WORKER_ID=3');
        $redis = $this->createMock(Redis::class);
        $redis->method('set')->willReturn(true);
        $redis->method('eval')->willReturn(0);
        $lease = WorkerId::acquire($redis);

        try {
            $lease->renew();
        } catch (RuntimeException) {
            // Mark the lease as lost before exercising Snowflake's guard.
        }

        $snowflake = new Snowflake($lease->workerId(), lease: $lease);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('lease 96 was lost');

        $snowflake->nextId();
    }

    public function testInvalidLeaseTtlIsRejectedBeforeRedisIsTouched(): void
    {
        putenv('WORKER_ID=3');
        putenv('WORKER_ID_LEASE_TTL_SECONDS=2');
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->never())->method('set');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at least 3 seconds');

        WorkerId::resolve($redis);
    }

    /** @return Redis&\PHPUnit\Framework\MockObject\MockObject */
    private function redisWithFreeSlots(): Redis
    {
        return $this->redisWithSetCallback(static fn (string $key, string $token, array $options): bool => true);
    }

    /**
     * @param callable(string, string, array): bool $callback
     * @return Redis&\PHPUnit\Framework\MockObject\MockObject
     */
    private function redisWithSetCallback(callable $callback): Redis
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('set')->willReturnCallback($callback);
        // Release is best-effort; renewal tests replace this with a stricter
        // expectation when ownership loss is being asserted.
        $redis->method('eval')->willReturn(1);

        return $redis;
    }
}
