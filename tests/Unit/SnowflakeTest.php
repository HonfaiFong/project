<?php

declare(strict_types=1);

namespace Tests\Unit;

use Packages\IdGenerator\Snowflake;
use Tests\TestCase;

final class SnowflakeTest extends TestCase
{
    public function testGeneratesAscendingUniqueIds(): void
    {
        $snowflake = new Snowflake(workerId: 1);
        $id1 = $snowflake->nextId();
        $id2 = $snowflake->nextId();

        $this->assertNotEmpty($id1);
        $this->assertNotEmpty($id2);
        $this->assertNotEquals($id1, $id2);
        $this->assertTrue(bccomp($id2, $id1) >= 0);
    }

    public function testParsesWorkerIdAndTimestampCorrectly(): void
    {
        $workerId = 42;
        $snowflake = new Snowflake(workerId: $workerId);
        $id = $snowflake->nextId();

        $parsed = $snowflake->parse($id);

        $this->assertSame($workerId, $parsed['worker_id']);
        $this->assertGreaterThan(1704067200000, $parsed['timestamp']);
        $this->assertLessThanOrEqual(floor(microtime(true) * 1000), $parsed['timestamp']);
    }
}
