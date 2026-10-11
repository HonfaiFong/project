<?php

declare(strict_types=1);

namespace Packages\IdGenerator;

use Random\RandomException;
use Redis;
use RuntimeException;
use Throwable;

/**
 * A Redis-backed lease for one Snowflake worker id.
 *
 * The Redis value is an opaque owner token.  Acquiring a slot is an atomic
 * SET NX EX operation; renewal and release compare that token in a Lua
 * script, so an expired owner can never delete or extend a newer owner's
 * lease.  A caller must invoke assertValid() before using the worker id.  It
 * renews periodically and fails closed when Redis reports that ownership was
 * lost or is unavailable.
 */
final class WorkerIdLease
{
    public const DEFAULT_TTL_SECONDS = 30;

    private const RENEWAL_FRACTION = 3;

    private bool $released = false;
    private bool $lost = false;
    private int $renewAtNanoseconds;

    /**
     * @param int $ttlSeconds A positive Redis EX TTL.  Values below three
     * seconds are rejected because they leave no safe renewal window.
     */
    private function __construct(
        private readonly Redis $redis,
        private readonly int $workerId,
        private readonly string $key,
        private readonly string $ownerToken,
        private readonly int $ttlSeconds,
    ) {
        $this->renewAtNanoseconds = $this->renewalDeadline();
    }

    /**
     * Acquire the first free process slot for a service slot.
     *
     * Each SET NX EX is atomic in Redis.  Multiple replicas can therefore
     * race through the same slots without ever being assigned the same live
     * key.  If all slots are occupied, startup fails instead of falling back
     * to a probabilistic or PID-derived id.
     *
     * @throws RandomException
     * @throws RuntimeException when configuration or Redis is invalid/unavailable
     */
    public static function acquire(
        Redis $redis,
        int $serviceSlot,
        int $processSlots = WorkerId::PROCESS_SLOTS,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        string $namespace = 'social-backend',
    ): self {
        self::validateConfiguration($serviceSlot, $processSlots, $ttlSeconds, $namespace);

        $ownerToken = bin2hex(random_bytes(16));
        $prefix = rtrim($namespace, ':') . ':worker-id:' . $serviceSlot . ':';

        for ($processSlot = 0; $processSlot < $processSlots; $processSlot++) {
            $key = $prefix . $processSlot;
            try {
                $acquired = $redis->set($key, $ownerToken, ['nx', 'ex' => $ttlSeconds]);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'Worker ID Redis lease acquisition failed; refusing to start',
                    0,
                    $e,
                );
            }

            if ($acquired === true || $acquired === 'OK') {
                return new self(
                    redis: $redis,
                    workerId: $serviceSlot * $processSlots + $processSlot,
                    key: $key,
                    ownerToken: $ownerToken,
                    ttlSeconds: $ttlSeconds,
                );
            }

            // phpredis reports both NX contention and some transport errors as
            // false. Distinguish an occupied slot from a broken Redis command
            // when the extension exposes its last error.
            try {
                $lastError = $redis->getLastError();
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'Worker ID Redis lease acquisition failed; refusing to start',
                    0,
                    $e,
                );
            }
            if (is_string($lastError) && trim($lastError) !== '') {
                throw new RuntimeException(
                    'Worker ID Redis lease acquisition failed; refusing to start: ' . $lastError,
                );
            }
        }

        throw new RuntimeException(
            sprintf(
                'No free Worker ID lease is available for service slot %d (tried %d process slots)',
                $serviceSlot,
                $processSlots,
            ),
        );
    }

    public function workerId(): int
    {
        return $this->workerId;
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * Exposed for diagnostics/tests; callers must never use this as an id.
     */
    public function ownerToken(): string
    {
        return $this->ownerToken;
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    /**
     * Renew only when this lease still owns the key.
     *
     * @throws RuntimeException if the Redis command fails or another owner
     * has already acquired the expired slot.
     */
    public function renew(): void
    {
        $this->ensureNotReleased();

        try {
            $result = $this->redis->eval(
                <<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[1] then return 0 end
return redis.call('EXPIRE', KEYS[1], ARGV[2])
LUA,
                [$this->key, $this->ownerToken, (string)$this->ttlSeconds],
                1,
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Worker ID Redis lease renewal failed; refusing to generate IDs',
                0,
                $e,
            );
        }

        if ((int)$result !== 1) {
            try {
                $lastError = $this->redis->getLastError();
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'Worker ID Redis lease renewal failed; refusing to generate IDs',
                    0,
                    $e,
                );
            }
            if (is_string($lastError) && trim($lastError) !== '') {
                throw new RuntimeException(
                    'Worker ID Redis lease renewal failed; refusing to generate IDs: ' . $lastError,
                );
            }

            $this->lost = true;
            throw new RuntimeException(
                sprintf('Worker ID lease %d was lost; refusing to generate IDs', $this->workerId),
            );
        }

        $this->renewAtNanoseconds = $this->renewalDeadline();
    }

    /**
     * Verify ownership before an ID is generated and periodically renew it.
     * hrtime() is monotonic, so wall-clock corrections cannot make the local
     * renewal schedule run backwards.
     */
    public function assertValid(): void
    {
        $this->ensureNotReleased();

        if ($this->lost || hrtime(true) >= $this->renewAtNanoseconds) {
            $this->renew();
        }
    }

    /**
     * Release only this token's lease.  If Redis is down during process exit,
     * the EX TTL remains the recovery mechanism.  The method is idempotent.
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        try {
            $this->redis->eval(
                <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA,
                [$this->key, $this->ownerToken],
                1,
            );
        } catch (Throwable) {
            // Redis unavailability cannot make us unsafe: no ID is generated
            // after release and the original TTL will reclaim the key.
        }
    }

    public function __destruct()
    {
        $this->release();
    }

    private function renewalDeadline(): int
    {
        return hrtime(true) + (int)floor($this->ttlSeconds * 1_000_000_000 / self::RENEWAL_FRACTION);
    }

    private function ensureNotReleased(): void
    {
        if ($this->released) {
            throw new RuntimeException('Worker ID lease has been released');
        }
    }

    private static function validateConfiguration(
        int $serviceSlot,
        int $processSlots,
        int $ttlSeconds,
        string $namespace,
    ): void {
        if ($serviceSlot < 0 || $serviceSlot >= WorkerId::SERVICE_SLOTS) {
            throw new RuntimeException(sprintf('Worker ID service slot must be between 0 and %d', WorkerId::SERVICE_SLOTS - 1));
        }
        if ($processSlots < 1 || $serviceSlot * $processSlots + $processSlots - 1 > 1023) {
            throw new RuntimeException('Worker ID process slot configuration exceeds the 10-bit Snowflake range');
        }
        if ($ttlSeconds < 3) {
            throw new RuntimeException('Worker ID lease TTL must be at least 3 seconds');
        }
        if (trim($namespace) === '' || !preg_match('/^[A-Za-z0-9_.:-]+$/', $namespace)) {
            throw new RuntimeException('Worker ID lease namespace contains invalid characters');
        }
    }
}
