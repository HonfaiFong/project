<?php

declare(strict_types=1);

/**
 * Local-only OAuth upstream used by the social HTTP E2E suite.
 *
 * The application points at this server only when the E2E environment opts in
 * to the explicit HTTP test endpoint. Codes are scoped by the runner suffix so
 * repeated local runs do not reuse database identities.
 */

function jsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

function failTransientOnce(string $key): bool
{
    $stateFile = sys_get_temp_dir() . '/social-e2e-oauth-failures.json';
    $state = [];
    if (is_file($stateFile)) {
        $decoded = json_decode((string)file_get_contents($stateFile), true);
        $state = is_array($decoded) ? $decoded : [];
    }
    if (isset($state[$key])) {
        return false;
    }
    $state[$key] = true;
    file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);

    return true;
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

    return [$parts[1], preg_replace('/[^A-Za-z0-9]/', '', $parts[2]) ?: 'unknown'];
}

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

if ($path === '/sns/oauth2/access_token') {
    [$scenario, $suffix] = parseScenario(queryCode(), 'wechat');
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
    [$scenario, $suffix] = parseScenario(queryCode(), 'qq');
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
