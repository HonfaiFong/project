<?php

declare(strict_types=1);

namespace Packages\IdGenerator;

use RuntimeException;
use Random\RandomException;

/**
 * 解析当前进程的 10 位 Snowflake 工作节点 ID。
 *
 * 布局：WORKER_ID 环境变量（服务槽位，0-31）* 32 + (pid % 32)。
 *
 * RoadRunner 在每个容器中启动多个 PHP 工作进程（pool.num_workers），每个进程拥有自己的 Snowflake 实例。固定工作
 * 节点 ID 会造成容器内各进程生成冲突 ID；按 PID 分散可区分容器内进程，环境变量指定的服务槽位则区分不同服务。
 *
 * 重要：生产部署说明
 * `pid % 32` 可用于第一阶段的单实例本地 MVP（如 pool.num_workers=2）。
 * 但若 PID 相差 32 的倍数，`pid % 32` 无法在任意数量的进程或工作进程重启之间从数学上保证唯一性。
 * 生产环境水平扩容（多 Pod × 多工作进程）之前，须改用基于 Redis `SET NX` 的分布式租约分配器（0-1023），定期续约
 * 心跳，并在进程退出后通过 TTL 过期回收。
 */
final class WorkerId
{
    public const SERVICE_SLOTS = 32;
    public const PROCESS_SLOTS = 32;

    /**
     * @throws RandomException
     * @throws RuntimeException
     */
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
