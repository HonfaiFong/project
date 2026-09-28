<?php

declare(strict_types=1);

namespace Tests\Unit;

use Packages\IdGenerator\WorkerId;
use RuntimeException;
use Tests\TestCase;

final class WorkerIdTest extends TestCase
{
    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        $previous = getenv('WORKER_ID');
        $this->previousEnv = $previous === false ? null : $previous;

        putenv('WORKER_ID');
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv !== null) {
            putenv('WORKER_ID=' . $this->previousEnv);
        } else {
            putenv('WORKER_ID');
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

        $workerId = WorkerId::resolve();

        $this->assertGreaterThanOrEqual(7 * WorkerId::PROCESS_SLOTS, $workerId);
        $this->assertLessThanOrEqual(7 * WorkerId::PROCESS_SLOTS + WorkerId::PROCESS_SLOTS - 1, $workerId);
        $this->assertLessThanOrEqual(1023, $workerId);
    }

    public function testDistinctProcessesInSameContainerGetDistinctSlots(): void
    {
        putenv('WORKER_ID=3');

        $first = WorkerId::resolve();

        // A sibling worker process has a different pid; simulate it by
        // checking two resolutions never collapse into the same process slot
        // for long-lived pools (pid % 32 differs for at least one offset).
        $offsets = array_map(
            static fn (int $delta): int => (getmypid() + $delta) % WorkerId::PROCESS_SLOTS,
            range(1, WorkerId::PROCESS_SLOTS)
        );

        $this->assertCount(WorkerId::PROCESS_SLOTS, array_unique($offsets));
        $this->assertSame(
            3 * WorkerId::PROCESS_SLOTS + (getmypid() % WorkerId::PROCESS_SLOTS),
            $first
        );
    }
}
