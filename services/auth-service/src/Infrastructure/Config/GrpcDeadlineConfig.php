<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Config;

use InvalidArgumentException;
use Packages\GrpcCommon\DeadlineEnv;

/**
 * 认证服务工作进程的截止时间配置，仅在启动时解析一次。
 *
 * 跨服务预算关系涉及两个部署：auth->user 用户目录调用预算须小于网关的外层认证截止时间，确保客户端观察到外层截止
 * 时间。因此 compose.yaml 也向本容器注入网关的 GRPC_AUTH_TIMEOUT_MS；违反预算关系时工作进程拒绝启动，避免在生产
 * 中通过大量 RPC_TIMEOUT 才发现问题。
 */
final readonly class GrpcDeadlineConfig
{
    public function __construct(
        public int $userDirectoryTimeoutMs,
        public int $gatewayAuthTimeoutMs,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $userDirectoryTimeoutMs = DeadlineEnv::positiveMilliseconds('GRPC_USER_DIRECTORY_TIMEOUT_MS', 250);
        $gatewayAuthTimeoutMs = DeadlineEnv::positiveMilliseconds('GRPC_AUTH_TIMEOUT_MS', 300);

        if ($userDirectoryTimeoutMs >= $gatewayAuthTimeoutMs) {
            throw new InvalidArgumentException(
                'GRPC_USER_DIRECTORY_TIMEOUT_MS (' . $userDirectoryTimeoutMs . 'ms) must stay below '
                . 'GRPC_AUTH_TIMEOUT_MS (' . $gatewayAuthTimeoutMs . 'ms): the auth worker needs headroom '
                . 'inside the gateway deadline for its own queries, otherwise every directory hop spends '
                . 'the whole outer budget and the client observes RPC_TIMEOUT instead of the inner error'
            );
        }

        return new self($userDirectoryTimeoutMs, $gatewayAuthTimeoutMs);
    }
}
