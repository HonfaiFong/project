<?php

declare(strict_types=1);

/*
 * API gateway runtime settings.
 *
 * Webman includes every file under config/ once per process at worker startup
 * (support/App::run() -> loadAllConfig()), so environment variables are read
 * only while this file is loaded. Request handling consumes the resolved values
 * through the container instead of re-reading env vars or config files.
 */

$environment = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));

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
            'timeout_ms' => (int)(getenv('GRPC_AUTH_TIMEOUT_MS') ?: 300),
            'social_login_timeout_ms' => (int)(getenv('GRPC_SOCIAL_LOGIN_TIMEOUT_MS') ?: 7000),
            'social_bind_timeout_ms' => (int)(getenv('GRPC_SOCIAL_BIND_TIMEOUT_MS') ?: 7000),
            'social_http_timeout_ms' => (int)(getenv('SOCIAL_HTTP_TIMEOUT_MS') ?: 3000),
            'service_token' => trim((string)(getenv('GRPC_AUTH_TOKEN') ?: '')) ?: null,
        ],
        'user' => [
            'target' => getenv('GRPC_USER_SERVICE') ?: 'user-service:50052',
            'service_token' => trim((string)(getenv('GRPC_USER_TOKEN') ?: '')) ?: null,
        ],
    ],
    'social_login_rate_limit' => [
        'limit' => (int)(getenv('SOCIAL_LOGIN_RATE_LIMIT') ?: 30),
        'window_seconds' => (int)(getenv('SOCIAL_LOGIN_RATE_WINDOW_SECONDS') ?: 60),
    ],
];
