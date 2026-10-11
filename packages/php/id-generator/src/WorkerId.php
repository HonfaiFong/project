<?php

declare(strict_types=1);

namespace Packages\IdGenerator;

use Random\RandomException;
use Redis;
use RuntimeException;
use Throwable;

/**
 * Resolves a 10-bit Snowflake worker id by taking a Redis lease.
 *
 * WORKER_ID remains the service slot (0-31), preserving the existing
 * deployment contract. The process slot (0-31) is allocated atomically in
 * Redis, so replicas and RoadRunner workers sharing a service slot cannot
 * use the same live worker id. The lease is kept in a process-local registry
 * so Snowflake can verify and renew it before generating an id.
 */
final class WorkerId
{
    public const SERVICE_SLOTS = 32;
    public const PROCESS_SLOTS = 32;

    /** @var array<int, WorkerIdLease> */
    private static array $leases = [];

    /**
     * Acquire and register a worker id lease.
     *
     * Passing an already-connected Redis client is preferred by services that
     * already need Redis (for example AuthService). Other service entrypoints
     * may omit it; the package then connects using REDIS_* environment
     * variables and fails startup if Redis is unavailable.
     *
     * @throws RandomException
     * @throws RuntimeException when configuration or Redis is invalid/unavailable
     */
    public static function resolve(?Redis $redis = null): int
    {
        $lease = self::acquire($redis);
        self::$leases[$lease->workerId()] = $lease;

        return $lease->workerId();
    }

    /**
     * Acquire a lease without registering it. This is useful to callers that
     * want to pass the lease explicitly to Snowflake.
     *
     * @throws RandomException
     * @throws RuntimeException
     */
    public static function acquire(?Redis $redis = null): WorkerIdLease
    {
        $serviceSlot = self::serviceSlot();
        $ttlSeconds = self::leaseTtlSeconds();
        $namespace = self::leaseNamespace();
        $redis ??= self::connectRedis();

        return WorkerIdLease::acquire(
            redis: $redis,
            serviceSlot: $serviceSlot,
            processSlots: self::PROCESS_SLOTS,
            ttlSeconds: $ttlSeconds,
            namespace: $namespace,
        );
    }

    /**
     * Snowflake uses this to attach the lease acquired by resolve().
     */
    public static function leaseFor(int $workerId): ?WorkerIdLease
    {
        return self::$leases[$workerId] ?? null;
    }

    /**
     * Release a registered lease early. Process shutdown also releases it;
     * the Redis TTL remains the recovery path when shutdown is unclean.
     */
    public static function release(int $workerId): void
    {
        $lease = self::$leases[$workerId] ?? null;
        unset(self::$leases[$workerId]);
        $lease?->release();
    }

    private static function serviceSlot(): int
    {
        $raw = getenv('WORKER_ID');
        if ($raw === false || trim((string)$raw) === '') {
            throw new RuntimeException(
                'WORKER_ID env is required (0-' . (self::SERVICE_SLOTS - 1) . '); '
                . 'a fixed default would collide across processes and replicas',
            );
        }

        $value = trim((string)$raw);
        if (!preg_match('/^\d+$/', $value)) {
            throw new RuntimeException(sprintf('WORKER_ID must be between 0 and %d, got %s', self::SERVICE_SLOTS - 1, $value));
        }

        $serviceSlot = (int)$value;
        if ($serviceSlot < 0 || $serviceSlot >= self::SERVICE_SLOTS) {
            throw new RuntimeException(sprintf('WORKER_ID must be between 0 and %d, got %d', self::SERVICE_SLOTS - 1, $serviceSlot));
        }

        return $serviceSlot;
    }

    private static function leaseTtlSeconds(): int
    {
        $raw = getenv('WORKER_ID_LEASE_TTL_SECONDS');
        if ($raw === false || trim((string)$raw) === '') {
            return WorkerIdLease::DEFAULT_TTL_SECONDS;
        }

        $value = trim((string)$raw);
        if (!preg_match('/^\d+$/', $value)) {
            throw new RuntimeException('WORKER_ID_LEASE_TTL_SECONDS must be an integer of at least 3 seconds');
        }

        $ttl = (int)$value;
        if ($ttl < 3) {
            throw new RuntimeException('WORKER_ID_LEASE_TTL_SECONDS must be at least 3 seconds');
        }

        return $ttl;
    }

    private static function leaseNamespace(): string
    {
        $namespace = trim((string)(getenv('WORKER_ID_LEASE_NAMESPACE') ?: 'social-backend'));
        if ($namespace === '') {
            throw new RuntimeException('WORKER_ID_LEASE_NAMESPACE must not be empty');
        }
        if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $namespace)) {
            throw new RuntimeException('WORKER_ID_LEASE_NAMESPACE contains invalid characters');
        }

        return $namespace;
    }

    /**
     * @throws RuntimeException when Redis cannot be connected/authenticated
     */
    private static function connectRedis(): Redis
    {
        $host = trim((string)(getenv('REDIS_HOST') ?: '127.0.0.1'));
        $port = self::positiveEnvironmentInteger('REDIS_PORT', 6379);
        $password = (string)(getenv('REDIS_PASSWORD') ?: '');
        $timeout = self::positiveEnvironmentFloat('WORKER_ID_REDIS_CONNECT_TIMEOUT_SECONDS', 1.0);

        try {
            $redis = new Redis();
            if (!$redis->connect($host, $port, $timeout)) {
                throw new RuntimeException('Redis connection returned false');
            }
            if ($password !== '' && !$redis->auth($password)) {
                throw new RuntimeException('Redis authentication returned false');
            }
            if ($redis->ping() === false) {
                throw new RuntimeException('Redis ping returned false');
            }

            return $redis;
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf('Worker ID Redis is unavailable at %s:%d; refusing to start', $host, $port),
                0,
                $e,
            );
        }
    }

    private static function positiveEnvironmentInteger(string $name, int $default): int
    {
        $raw = getenv($name);
        if ($raw === false || trim((string)$raw) === '') {
            return $default;
        }

        $value = trim((string)$raw);
        if (!preg_match('/^\d+$/', $value) || (int)$value < 1) {
            throw new RuntimeException(sprintf('%s must be a positive integer', $name));
        }

        return (int)$value;
    }

    private static function positiveEnvironmentFloat(string $name, float $default): float
    {
        $raw = getenv($name);
        if ($raw === false || trim((string)$raw) === '') {
            return $default;
        }

        $value = trim((string)$raw);
        if (!is_numeric($value) || (float)$value <= 0) {
            throw new RuntimeException(sprintf('%s must be a positive number', $name));
        }

        return (float)$value;
    }
}
