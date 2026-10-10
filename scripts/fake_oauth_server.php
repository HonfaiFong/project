<?php

declare(strict_types=1);

/**
 * 仅用于本地社交 HTTP 端到端测试的模拟 OAuth 上游服务。
 *
 * 只有端到端测试环境显式选择 HTTP 测试端点时，应用才连接此服务器。授权码按测试运行后缀隔离，避免重复本地运行复用
 * 数据库身份。
 *
 * 授权码严格遵循微信和 QQ 的一次性约定：每次兑换尝试均消耗完整授权码，临时 503 也会使其失效，重放返回提供方的无
 * 效授权码错误。身份部分相同（可选“~nonce”之前）的不同授权码仍对应同一 openid/unionid，测试因此能在失败后为同一
 * 外部身份获取新授权码。
 */

function consumedCodeStateFile(): string
{
    // 允许覆盖，以便测试让并发工作进程使用独立文件。
    $directory = getenv('FAKE_OAUTH_STATE_DIR');
    return ($directory !== false && $directory !== '' ? $directory : sys_get_temp_dir())
        . '/social-e2e-oauth-consumed-codes.json';
}

/**
 * 在排他 flock 锁保护下，对 JSON 状态文件执行读取、检查和写入的完整流程。file_put_contents(..., LOCK_EX) 仅保护
 * 写入，两个进程可能都读到“未消费”并同时通过检查；锁必须覆盖完整流程，否则模拟服务使用多个工作进程时会破坏一次性
 * 语义。
 *
 * @template T
 * @param callable(array<string, mixed>&): T $operation
 * @return T
 */
function withLockedState(string $stateFile, callable $operation): mixed
{
    $handle = fopen($stateFile, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Cannot open fake OAuth state file: ' . $stateFile);
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Cannot lock fake OAuth state file: ' . $stateFile);
        }

        try {
            $raw = stream_get_contents($handle);
            $state = $raw === false || $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($state)) {
                $state = [];
            }

            $result = $operation($state);

            $encoded = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($handle);
            fwrite($handle, $encoded);
            ftruncate($handle, strlen($encoded));
            fflush($handle);

            return $result;
        } finally {
            flock($handle, LOCK_UN);
        }
    } finally {
        fclose($handle);
    }
}

/**
 * 记录完整授权码的一次兑换尝试；首次尝试返回 true，已失效授权码返回 false。
 */
function consumeCode(string $provider, string $code): bool
{
    return withLockedState(
        consumedCodeStateFile(),
        static function (array &$state) use ($provider, $code): bool {
            $key = $provider . ':' . $code;
            if (isset($state[$key])) {
                return false;
            }

            $state[$key] = true;

            return true;
        }
    );
}

function jsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

function failTransientOnce(string $key): bool
{
    return withLockedState(
        sys_get_temp_dir() . '/social-e2e-oauth-failures.json',
        static function (array &$state) use ($key): bool {
            if (isset($state[$key])) {
                return false;
            }
            $state[$key] = true;

            return true;
        }
    );
}

function queryCode(): string
{
    return trim((string)($_GET['code'] ?? ''));
}

function parseScenario(string $code, string $provider): array
{
    $parts = explode('-', $code, 3);
    if (count($parts) !== 3 || $parts[0] !== $provider || !in_array($parts[1], ['transient', 'primary', 'conflict'], true)) {
        jsonResponse(200, ['errcode' => 40029, 'errmsg' => 'invalid code']);
    }

    // 不同的一次性授权码可表示同一身份；可选随机数仅用于测试，不参与主体 ID。
    $identitySuffix = explode('~', $parts[2], 2)[0];

    return [$parts[1], preg_replace('/[^A-Za-z0-9]/', '', $identitySuffix) ?: 'unknown'];
}

// 路由脚本仅在处理请求（php -S）时分发；普通 CLI 下由测试引入，用于验证上方锁相关辅助函数。
if (PHP_SAPI !== 'cli-server') {
    return;
}

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

if ($path === '/sns/oauth2/access_token') {
    $code = queryCode();
    [$scenario, $suffix] = parseScenario($code, 'wechat');
    // 40163 是微信的“授权码已使用”错误；WeChatOAuthProvider 将其映射为无效授权码业务错误，确保端到端拒绝重放。
    if (!consumeCode('wechat', $code)) {
        jsonResponse(200, ['errcode' => 40163, 'errmsg' => 'code been used']);
    }
    $key = 'wechat-' . $scenario . '-' . $suffix;
    if ($scenario === 'transient' && failTransientOnce($key)) {
        jsonResponse(503, ['errcode' => 50001, 'errmsg' => 'transient fake upstream failure']);
    }

    jsonResponse(200, [
        'access_token' => 'wechat-token-' . $scenario . '-' . $suffix,
        'openid'       => 'wechat-openid-' . $scenario . '-' . $suffix,
        'unionid'      => 'wechat-unionid-' . $scenario . '-' . $suffix,
    ]);
}

if ($path === '/oauth2.0/token') {
    $code = queryCode();
    [$scenario, $suffix] = parseScenario($code, 'qq');
    // 已消耗授权码的重放返回错误响应体；QqOAuthProvider 将所有令牌错误映射为无效授权码业务错误。
    if (!consumeCode('qq', $code)) {
        header('Content-Type: text/plain');
        echo 'error=100030&error_description=' . urlencode('authorization code already redeemed');
        exit;
    }
    $key = 'qq-' . $scenario . '-' . $suffix;
    if ($scenario === 'transient' && failTransientOnce($key)) {
        http_response_code(503);
        header('Content-Type: text/plain');
        echo 'fake transient upstream failure';
        exit;
    }

    header('Content-Type: text/plain');
    echo 'access_token=qq-token-' . $scenario . '-' . $suffix . '&expires_in=3600';
    exit;
}

if ($path === '/oauth2.0/me') {
    $accessToken = trim((string)($_GET['access_token'] ?? ''));
    $parts = explode('-', $accessToken, 4);
    if (count($parts) !== 4 || $parts[0] !== 'qq' || $parts[1] !== 'token') {
        http_response_code(200);
        header('Content-Type: text/plain');
        echo 'callback({"error":100030});';
        exit;
    }

    header('Content-Type: text/plain');
    echo 'callback(' . json_encode([
        'client_id' => 'e2e-qq-app',
        'openid'    => 'qq-openid-' . $parts[2] . '-' . $parts[3],
    ], JSON_THROW_ON_ERROR) . ');';
    exit;
}

jsonResponse(404, ['error' => 'unknown fake OAuth path']);
