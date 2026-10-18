<?php

declare(strict_types=1);

namespace Tests\Integration;

use Packages\GrpcCommon\LeaseMaintainingRelay;
use Packages\IdGenerator\Snowflake;
use Packages\IdGenerator\WorkerId;
use PHPUnit\Framework\TestCase;
use Redis;
use Spiral\Goridge\Frame;
use Spiral\Goridge\StreamRelay;

final class WorkerLeaseIdleTest extends TestCase
{
    public function testIdleRelayRenewsRealRedisLeaseAcrossMultipleTtls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Requires selectable Linux process pipes');
        }
        $redis = new Redis();
        try {
            $redis->connect(getenv('SOCIAL_TEST_REDIS_HOST') ?: '127.0.0.1', (int)(getenv('SOCIAL_TEST_REDIS_PORT') ?: 6379), 1.0);
        } catch (\RedisException) {
            self::markTestSkipped('Redis integration service is not available');
        }
        $previous = [];
        foreach (['WORKER_ID' => '31', 'WORKER_ID_LEASE_TTL_SECONDS' => '3', 'WORKER_ID_LEASE_NAMESPACE' => 'idle-test-' . bin2hex(random_bytes(6))] as $name => $value) {
            $previous[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
        $id = WorkerId::resolve($redis);
        $lease = WorkerId::leaseFor($id);
        self::assertNotNull($lease);
        $snowflake = new Snowflake($id);
        $first = $snowflake->nextId();
        $frame = new Frame('resume');
        $process = proc_open([PHP_BINARY, '-r', 'sleep(7); fwrite(STDOUT, hex2bin($argv[1]));', bin2hex(Frame::packFrame($frame))], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        try {
            $relay = new LeaseMaintainingRelay(new StreamRelay($pipes[1], $output));
            self::assertSame('resume', $relay->waitFrame()->payload);
            self::assertSame($lease->ownerToken(), $redis->get($lease->key()));
            self::assertGreaterThan(0, $redis->ttl($lease->key()));
            self::assertGreaterThan((int)$first, (int)$snowflake->nextId());
            // A competing process still cannot claim the maintained slot.
            self::assertFalse($redis->set($lease->key(), 'competitor', ['nx', 'ex' => 3]));
        } finally {
            fclose($pipes[1]);
            fclose($output);
            proc_terminate($process, 9);
            proc_close($process);
            WorkerId::release($id);
            foreach ($previous as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }
}
