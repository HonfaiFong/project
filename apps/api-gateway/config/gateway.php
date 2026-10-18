<?php

declare(strict_types=1);

use Apps\ApiGateway\Grpc\AuthServiceClient;
use Packages\GrpcCommon\DeadlineEnv;

/*
 * API 网关运行时配置。
 *
 * Webman 在工作进程启动时，通过 support/App::run() -> loadAllConfig() 为每个进程加载 config/ 下的每个文件一次。
 * 因此环境变量仅在本文件加载时读取；请求处理通过容器使用已解析的值，无须重新读取环境变量或配置文件。
 */

$environment = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));

// 与 auth-service 启动流程共用 Packages\GrpcCommon\DeadlineEnv：截止时间会转换为微秒级 gRPC 超时，因此零、负数或
// 非数值配置须明确报错，不能截断修正，也不能通过把值视为假的“?:”写法回退。
$deadlineSetting = DeadlineEnv::positiveMilliseconds(...);

$grpcDeadlineSettings = [
    'GRPC_AUTH_TIMEOUT_MS'          => $deadlineSetting('GRPC_AUTH_TIMEOUT_MS', 300),
    'GRPC_CONTENT_TIMEOUT_MS'       => $deadlineSetting('GRPC_CONTENT_TIMEOUT_MS', 500),
    'GRPC_SOCIAL_TIMEOUT_MS'        => $deadlineSetting('GRPC_SOCIAL_TIMEOUT_MS', 500),
    'GRPC_CHAT_TIMEOUT_MS'          => $deadlineSetting('GRPC_CHAT_TIMEOUT_MS', 700),
    'GRPC_MESSAGE_TIMEOUT_MS'       => $deadlineSetting('GRPC_MESSAGE_TIMEOUT_MS', 700),
    'GRPC_SOCIAL_LOGIN_TIMEOUT_MS'  => $deadlineSetting('GRPC_SOCIAL_LOGIN_TIMEOUT_MS', 7000),
    'GRPC_SOCIAL_BIND_TIMEOUT_MS'   => $deadlineSetting('GRPC_SOCIAL_BIND_TIMEOUT_MS', 7000),
    'SOCIAL_HTTP_TIMEOUT_MS'        => $deadlineSetting('SOCIAL_HTTP_TIMEOUT_MS', 3000),
];

// 加载配置时校验预算之间的关系，先于客户端的延迟解析。
AuthServiceClient::validateTimeouts(
    $grpcDeadlineSettings['GRPC_AUTH_TIMEOUT_MS'],
    $grpcDeadlineSettings['GRPC_SOCIAL_LOGIN_TIMEOUT_MS'],
    $grpcDeadlineSettings['GRPC_SOCIAL_BIND_TIMEOUT_MS'],
    $grpcDeadlineSettings['SOCIAL_HTTP_TIMEOUT_MS'],
);

// 限流窗口用于计数而非截止时间，但规则相同：无效环境配置须在工作进程启动时明确报错。
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
    // 仅区分两种行为：开发环境（local、development、dev、testing）和生产环境（其他所有情况，包括缺少 APP_ENV）。
    'environment' => in_array($environment, ['local', 'development', 'dev', 'testing'], true)
        ? 'development'
        : 'production',
    'trusted_proxy_cidrs' => array_values(array_filter(
        array_map('trim', explode(',', (string)(getenv('SOCIAL_TRUSTED_PROXY_CIDRS') ?: ''))),
        static fn (string $cidr): bool => $cidr !== '',
    )),
    'metrics_allowed_cidrs' => array_values(array_filter(
        array_map('trim', explode(',', (string)(getenv('METRICS_ALLOWED_CIDRS') ?: ''))),
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
        'content' => [
            'target' => getenv('GRPC_CONTENT_SERVICE') ?: 'content-service:50053',
            'timeout_ms' => $grpcDeadlineSettings['GRPC_CONTENT_TIMEOUT_MS'],
            'service_token' => trim((string)(getenv('GRPC_CONTENT_TOKEN') ?: '')) ?: null,
        ],
        'social' => [
            'target' => getenv('GRPC_SOCIAL_SERVICE') ?: 'social-service:50054',
            'timeout_ms' => $grpcDeadlineSettings['GRPC_SOCIAL_TIMEOUT_MS'],
            'service_token' => trim((string)(getenv('GRPC_SOCIAL_TOKEN') ?: '')) ?: null,
        ],
        'chat' => [
            'target' => getenv('GRPC_CHAT_SERVICE') ?: 'chat-service:50055',
            'timeout_ms' => $grpcDeadlineSettings['GRPC_CHAT_TIMEOUT_MS'],
            'service_token' => trim((string)(getenv('GRPC_CHAT_TOKEN') ?: '')) ?: null,
        ],
        'message' => [
            'target' => getenv('GRPC_MESSAGE_SERVICE') ?: 'message-service:50056',
            'timeout_ms' => $grpcDeadlineSettings['GRPC_MESSAGE_TIMEOUT_MS'],
            'service_token' => trim((string)(getenv('GRPC_MESSAGE_TOKEN') ?: '')) ?: null,
        ],
    ],
    'social_login_rate_limit' => [
        // 分层限流桶：先对登录和绑定设置宽松的每 IP 总上限（运营商 CGNAT 会让真实用户共享 IPv4），再对 IP 与场景、IP 与
        // 设备、以及绑定用户分别施加更严格的限制。
        'limit' => $rateLimitSetting('SOCIAL_LOGIN_RATE_LIMIT', 60),
        'window_seconds' => $rateLimitSetting('SOCIAL_LOGIN_RATE_WINDOW_SECONDS', 60),
        'scenario_limit' => $rateLimitSetting('SOCIAL_LOGIN_RATE_SCENARIO_LIMIT', 30),
        'device_limit' => $rateLimitSetting('SOCIAL_LOGIN_RATE_DEVICE_LIMIT', 20),
    ],
    'social_bind_rate_limit' => [
        'user_limit' => $rateLimitSetting('SOCIAL_BIND_USER_RATE_LIMIT', 10),
        'user_window_seconds' => $rateLimitSetting('SOCIAL_BIND_USER_RATE_WINDOW_SECONDS', 3600),
    ],
    'logout_rate_limit' => [
        'limit' => $rateLimitSetting('AUTH_LOGOUT_RATE_LIMIT', 60),
        'window_seconds' => $rateLimitSetting('AUTH_LOGOUT_RATE_WINDOW_SECONDS', 60),
        'device_limit' => $rateLimitSetting('AUTH_LOGOUT_RATE_DEVICE_LIMIT', 20),
    ],
];
