<?php

declare(strict_types=1);

namespace Packages\IdGenerator;

use RuntimeException;

/**
 * Resolves the 10-bit Snowflake worker id for the current process.
 *
 * Layout: WORKER_ID env (service slot, 0-31) * 32 + (pid % 32).
 *
 * RoadRunner spawns multiple PHP worker processes per container (pool.num_workers),
 * each building its own Snowflake instance. A fixed worker id would make every
 * process in a container issue colliding ids. Spreading by pid keeps processes
 * within one container disjoint, and the env-prefixed service slot keeps
 * services disjoint from each other.
 *
 * IMPORTANT / PRODUCTION DEPLOYMENT NOTE:
 * `pid % 32` is acceptable for Phase 1 single-instance local MVP (e.g. pool.num_workers=2).
 * However, `pid % 32` cannot mathematically guarantee uniqueness across arbitrarily many
 * processes or worker recycles if PIDs differ by multiples of 32.
 * Before horizontal scaling in production (multi-Pod × multi-Worker), this must be
 * replaced by a distributed Redis `SET NX` lease allocator (0-1023) with periodic
 * heartbeat renewal and TTL expiration upon process exit.
 */
final class WorkerId
{
    public const SERVICE_SLOTS = 32;
    public const PROCESS_SLOTS = 32;

    public static function resolve(): int
    {
        $raw = getenv('WORKER_ID');
        if ($raw === false || trim((string)$raw) === '') {
            throw new RuntimeException(
                'WORKER_ID env is required (0-' . (self::SERVICE_SLOTS - 1) . '); '
                . 'a fixed default would collide across processes and replicas'
            );
        }

        $serviceSlot = (int)$raw;
        if ($serviceSlot < 0 || $serviceSlot >= self::SERVICE_SLOTS) {
            throw new RuntimeException(sprintf('WORKER_ID must be between 0 and %d, got %d', self::SERVICE_SLOTS - 1, $serviceSlot));
        }

        $pid = getmypid();
        $processSlot = $pid === false ? random_int(0, self::PROCESS_SLOTS - 1) : $pid % self::PROCESS_SLOTS;

        return $serviceSlot * self::PROCESS_SLOTS + $processSlot;
    }
}
