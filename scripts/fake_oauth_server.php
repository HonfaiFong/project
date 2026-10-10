<?php

declare(strict_types=1);

/**
 * Local-only OAuth upstream used by the social HTTP E2E suite.
 *
 * The application points at this server only when the E2E environment opts in
 * to the explicit HTTP test endpoint. Codes are scoped by the runner suffix so
 * repeated local runs do not reuse database identities.
 *
 * Authorization codes are strictly single-use, mirroring the real WeChat/QQ
 * contract: every redemption attempt consumes the exact code (a transient 503
 * burns it too), and any replay is answered with the providers' invalid-code
 * error. Distinct codes with an identical identity part (before the optional
 * "~nonce") still resolve to the same openid/unionid, so the suite can issue a
 * fresh authorization code for the same external identity after a failure.
 */

function consumedCodeStateFile(): string
{
    // Overridable so tests can point concurrent workers at an isolated file.
    $directory = getenv('FAKE_OAUTH_STATE_DIR');
    return ($directory !== false && $directory !== '' ? $directory : sys_get_temp_dir())
        . '/social-e2e-oauth-consumed-codes.json';
}

/**
 * Runs a read-check-write cycle on a JSON state file under an exclusive flock.
 * file_put_contents(..., LOCK_EX) only guards the write: two workers could
 * both read "not consumed" and both win the check. The lock must cover the
 * whole cycle, or single-use semantics break the moment the fake server runs
 * with more than one worker.
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
 * Records one redemption attempt for the exact code. Returns true when this is
 * the first attempt, false when the code was already burned.
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

    // Distinct single-use authorization codes can represent the same identity.
    // The optional nonce is test-only and never contributes to the subject id.
    $identitySuffix = explode('~', $parts[2], 2)[0];

    return [$parts[1], preg_replace('/[^A-Za-z0-9]/', '', $identitySuffix) ?: 'unknown'];
}

// The router script only dispatches when serving requests (php -S); under
// plain CLI it is included by tests exercising the locking helpers above.
if (PHP_SAPI !== 'cli-server') {
    return;
}

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

if ($path === '/sns/oauth2/access_token') {
    $code = queryCode();
    [$scenario, $suffix] = parseScenario($code, 'wechat');
    // 40163 is WeChat's "code been used" error; WeChatOAuthProvider maps it to
    // the invalid-code business error, so replays fail closed end to end.
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
    // A burned code replays with an error body; QqOAuthProvider maps any token
    // error to the invalid-code business error.
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
