<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Config;

use InvalidArgumentException;
use Packages\GrpcCommon\DeadlineEnv;

/**
 * Deadline knobs for the auth-service worker, resolved once at bootstrap.
 *
 * The cross-service relationship — the auth->user directory hop must stay
 * below the gateway's outer auth deadline so the outer deadline is what
 * clients observe — spans two deployments. compose.yaml therefore injects the
 * gateway's GRPC_AUTH_TIMEOUT_MS into this container as well, and the worker
 * refuses to start when the deployed pair violates the relationship instead
 * of discovering it as RPC_TIMEOUT traffic in production.
 */
final readonly class GrpcDeadlineConfig
{
    public function __construct(
        public readonly int $userDirectoryTimeoutMs,
        public readonly int $gatewayAuthTimeoutMs,
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
