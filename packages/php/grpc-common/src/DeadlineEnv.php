<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

use InvalidArgumentException;

/**
 * 严格解析 gRPC 截止时间相关环境配置。
 *
 * 截止时间会转换为微秒级 gRPC 超时，零、负数或非数值配置须在启动时明确报错。“(int)”转换会把无效值静默截断成 1 毫
 * 秒等数值，导致所有调用超时；“?:”写法又会用默认值掩盖缺失的覆盖配置。网关配置和 auth-service 启动流程共用此处的
 * 解析规则。
 */
final class DeadlineEnv
{
    /**
     * 解析以正整数毫秒表示的截止时间预算；未设置或为空时使用内置默认值，其他值必须是正整数字面量。
     */
    public static function positiveMilliseconds(string $name, int $default): int
    {
        $raw = getenv($name);
        $raw = $raw === false ? '' : trim($raw);
        if ($raw === '') {
            return $default;
        }
        if (!ctype_digit($raw) || (int)$raw < 1) {
            throw new InvalidArgumentException(
                $name . ' must resolve to a positive deadline in milliseconds, got \'' . $raw . '\''
            );
        }

        return (int)$raw;
    }
}
