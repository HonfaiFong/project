<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

/**
 * 保存每次调用的剩余截止时间，沿用 TraceContext 的工作进程局部模式。RoadRunner 的 gRPC 传输层不向 PHP 工作进程暴
 * 露入站截止时间，因此网关通过 x-deadline-unix-ms 元数据传递绝对截止时间；接收服务在调用方授权后将其保存于此，下
 * 游各跳预算限制在剩余时间内，确保外层截止时间始终起决定作用。
 */
final class DeadlineContext
{
    private static ?int $deadlineEpochMs = null;

    public static function setCurrent(?int $deadlineEpochMs): void
    {
        self::$deadlineEpochMs = $deadlineEpochMs;
    }

    public static function current(): ?int
    {
        return self::$deadlineEpochMs;
    }

    /**
     * 当前调用截止前的剩余毫秒数；调用方未传递截止时间时返回 null，沿用配置预算。
     */
    public static function remainingMs(): ?int
    {
        if (self::$deadlineEpochMs === null) {
            return null;
        }

        return max(0, self::$deadlineEpochMs - self::nowEpochMs());
    }

    /**
     * 将单跳配置预算限制在剩余截止时间内；null 表示外层截止时间已过，必须立即失败，不能消耗调用方未授予的完整新预算。
     */
    public static function clampHopBudgetMs(int $configuredMs): ?int
    {
        $remainingMs = self::remainingMs();
        if ($remainingMs === null) {
            return $configuredMs;
        }
        if ($remainingMs <= 0) {
            return null;
        }

        return max(1, min($configuredMs, $remainingMs));
    }

    public static function reset(): void
    {
        self::$deadlineEpochMs = null;
    }

    public static function nowEpochMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }
}
