<?php

declare(strict_types=1);

use Apps\ApiGateway\Grpc\AuthServiceClient;
use Packages\GrpcCommon\DeadlineEnv;

/*
 * API gateway runtime settings.
 *
 * Webman includes every file under config/ once per process at worker startup
 * (support/App::run() -> loadAllConfig()), so environment variables are read
 * only while this file is loaded. Request handling consumes the resolved values
 * through the container instead of re-reading env vars or config files.
 */

$environment = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));

// Shared with the auth-service bootstrap (Packages\GrpcCommon\DeadlineEnv):
// deadlines become gRPC timeouts in microseconds, so a zero, negative, or
// non-numeric value must fail loudly instead of clamping or falling back
// through the falsy "?:" idiom.
$deadlineSetting = DeadlineEnv::positiveMilliseconds(...);

$grpcDeadlineSettings = [
    'GRPC_AUTH_TIMEOUT_MS'          => $deadlineSetting('GRPC_AUTH_TIMEOUT_MS', 300),
    'GRPC_SOCIAL_LOGIN_TIMEOUT_MS'  => $deadlineSetting('GRPC_SOCIAL_LOGIN_TIMEOUT_MS', 7000),
    'GRPC_SOCIAL_BIND_TIMEOUT_MS'   => $deadlineSetting('GRPC_SOCIAL_BIND_TIMEOUT_MS', 7000),
    'SOCIAL_HTTP_TIMEOUT_MS'        => $deadlineSetting('SOCIAL_HTTP_TIMEOUT_MS', 3000),
];

// Validate relationships while loading config, before lazy client resolution.
AuthServiceClient::validateTimeouts(
    $grpcDeadlineSettings['GRPC_AUTH_TIMEOUT_MS'],
    $grpcDeadlineSettings['GRPC_SOCIAL_LOGIN_TIMEOUT_MS'],
    $grpcDeadlineSettings['GRPC_SOCIAL_BIND_TIMEOUT_MS'],
    $grpcDeadlineSettings['SOCIAL_HTTP_TIMEOUT_MS'],
);

// Rate limit windows are counters, not deadlines, but the same rule applies:
// garbage from the environment must fail loudly at worker start.
$rateLimitSetting = static function (string $name, int $default): int {
    $raw = getenv($name);
    $raw = $raw === false ? '' : trim($raw);
    if ($raw === '') {
        return $default;
    }
    if (!ctype_digit($raw) || (int)$raw < 1) {
        throw new InvalidArgumentException($name . ' must resolve to a positive integer, got \'' . $raw . '\'');
    }

    return (int)$raw;
};

return [
    // Only two behaviours exist: development (local, development, dev, testing)
    // and production (everything else, including a missing APP_ENV).
    'environment' => in_array($environment, ['local', 'development', 'dev', 'testing'], true)
        ? 'development'
        : 'production',
    'trusted_proxy_cidrs' => array_values(array_filter(
        array_map('trim', explode(',', (string)(getenv('SOCIAL_TRUSTED_PROXY_CIDRS') ?: ''))),
        static fn (string $cidr): bool => $cidr !== '',
    )),
    'redis' => [
        'host' => trim((string)(getenv('REDIS_HOST') ?: '')),
        'port' => (int)(getenv('REDIS_PORT') ?: 6379),
        'password' => trim((string)(getenv('REDIS_PASSWORD') ?: '')),
        'connect_timeout' => 1.0,
        'read_timeout' => 1.0,
    ],
    'grpc' => [
        'auth' => [
            'target' => getenv('GRPC_AUTH_SERVICE') ?: 'auth-service:50051',
            'timeout_ms' => $grpcDeadlineSettings['GRPC_AUTH_TIMEOUT_MS'],
            'social_login_timeout_ms' => $grpcDeadlineSettings['GRPC_SOCIAL_LOGIN_TIMEOUT_MS'],
            'social_bind_timeout_ms' => $grpcDeadlineSettings['GRPC_SOCIAL_BIND_TIMEOUT_MS'],
            'social_http_timeout_ms' => $grpcDeadlineSettings['SOCIAL_HTTP_TIMEOUT_MS'],
            'service_token' => trim((string)(getenv('GRPC_AUTH_TOKEN') ?: '')) ?: null,
        ],
        'user' => [
            'target' => getenv('GRPC_USER_SERVICE') ?: 'user-service:50052',
            'service_token' => trim((string)(getenv('GRPC_USER_TOKEN') ?: '')) ?: null,
        ],
    ],
    'social_login_rate_limit' => [
        // Layered buckets: one generous per-IP ceiling across login + bind
        // (carrier CGNAT shares one IPv4 across real users), then tighter
        // per-IP-per-scenario, per-(IP, device), and per-user (bind) limits.
        'limit' => $rateLimitSetting('SOCIAL_LOGIN_RATE_LIMIT', 60),
        'window_seconds' => $rateLimitSetting('SOCIAL_LOGIN_RATE_WINDOW_SECONDS', 60),
        'scenario_limit' => $rateLimitSetting('SOCIAL_LOGIN_RATE_SCENARIO_LIMIT', 30),
        'device_limit' => $rateLimitSetting('SOCIAL_LOGIN_RATE_DEVICE_LIMIT', 20),
    ],
    'social_bind_rate_limit' => [
        'user_limit' => $rateLimitSetting('SOCIAL_BIND_USER_RATE_LIMIT', 10),
        'user_window_seconds' => $rateLimitSetting('SOCIAL_BIND_USER_RATE_WINDOW_SECONDS', 3600),
    ],
];
