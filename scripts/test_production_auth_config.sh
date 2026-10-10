#!/usr/bin/env bash
set -euo pipefail

# Production AuthService startup checks.
#
#   1. A production worker with social login enabled and NO SMS configuration
#      must start and report readiness. Readiness is the worker's own startup
#      log line, so an early exit or a timeout is a failure and never a success.
#   2. Test-only OAuth endpoint overrides and insecure OAuth HTTP must fail
#      closed before the RoadRunner worker starts.

READY_MARKER='AuthService started with RoadRunner gRPC worker'
READY_TIMEOUT_SECONDS="${AUTH_READY_TIMEOUT_SECONDS:-120}"
PROBE_WINDOW_SECONDS="${AUTH_PROBE_WINDOW_SECONDS:-60}"
PROBE_RPC_DEADLINE_MS="${AUTH_PROBE_RPC_DEADLINE_MS:-30000}"
# Scoped to this run so concurrent runs never remove each other's container.
READY_CONTAINER="social-auth-config-readiness-${RANDOM}-$$"
READY_LOG=''

cleanup() {
  if [[ -n "$READY_LOG" ]]; then
    rm -f "$READY_LOG"
  fi
  docker rm -f "$READY_CONTAINER" >/dev/null 2>&1 || true
}
trap cleanup EXIT

run_rejection_case() {
  local name="$1"
  local expected_message="$2"
  shift 2

  local log_file
  log_file="$(mktemp)"

  if docker compose run --rm --no-deps "$@" auth-service php /app/services/auth-service/bin/server.php >"$log_file" 2>&1; then
    echo "FAIL case=${name} expected_auth_startup_rejection"
    rm -f "$log_file"
    return 1
  fi

  if ! grep -Fq "$expected_message" "$log_file"; then
    echo "FAIL case=${name} expected_guard_message_missing"
    sed -n '1,20p' "$log_file"
    rm -f "$log_file"
    return 1
  fi

  rm -f "$log_file"
  echo "PASS case=${name}"
}

run_ready_case() {
  local name="$1"
  shift

  local log_file pid attempt ready=0
  log_file="$(mktemp)"
  READY_LOG="$log_file"

  # The container runs the real production command (RoadRunner supervising the
  # AuthService worker), so the probe below talks to a truly served gRPC port.
  docker compose run --rm --no-deps --name "$READY_CONTAINER" "$@" auth-service >"$log_file" 2>&1 &
  pid=$!

  for ((attempt = 0; attempt < READY_TIMEOUT_SECONDS * 4; attempt++)); do
    if grep -Fq "$READY_MARKER" "$log_file"; then
      ready=1
      break
    fi
    if ! kill -0 "$pid" 2>/dev/null; then
      break
    fi
    sleep 0.25
  done

  local probe_output='' probe_status=1 probe_deadline=0
  if [[ "$ready" -eq 1 ]]; then
    probe_deadline=$((SECONDS + PROBE_WINDOW_SECONDS))
    while [[ "$SECONDS" -lt "$probe_deadline" ]]; do
      probe_status=0
      probe_output="$(probe_auth_service "$READY_CONTAINER" 2>&1)" || probe_status=$?
      if [[ "$probe_status" -eq 0 ]]; then
        break
      fi
      sleep 1
    done
    if [[ "$probe_status" -ne 0 ]]; then
      ready=0
    fi
  fi

  docker rm -f "$READY_CONTAINER" >/dev/null 2>&1 || true
  wait "$pid" 2>/dev/null || true

  if [[ "$ready" -ne 1 ]]; then
    echo "FAIL case=${name} expected_auth_startup_readiness"
    if [[ -n "$probe_output" ]]; then
      echo "probe: ${probe_output}"
    fi
    sed -n '1,20p' "$log_file"
    return 1
  fi

  rm -f "$log_file"
  READY_LOG=''
  echo "PASS case=${name}"
}

# Probes the running AuthService container over gRPC. Only this probe process
# overrides SERVICE_NAME: the gateway caller identity is what the AuthService
# authorizer expects, and it never prints the service token.
probe_auth_service() {
  local container="$1"
  docker exec \
    -e SERVICE_NAME=api-gateway \
    -e AUTH_PROBE_RPC_DEADLINE_MS="$PROBE_RPC_DEADLINE_MS" \
    "$container" \
    php -r "$(cat <<'PHP'
require '/app/vendor/autoload.php';

$token = trim((string)(getenv('INTERNAL_RPC_GATEWAY_AUTH_TOKEN') ?: ''));
if ($token === '') {
    fwrite(STDERR, "internal RPC token is missing" . PHP_EOL);
    exit(2);
}

$deadline = (int)(getenv('AUTH_PROBE_RPC_DEADLINE_MS') ?: 30000);
$client = new \Packages\GrpcCommon\UnaryGrpcClient('127.0.0.1:50051', $token);

$health = $client->call(
    '/common.v1.HealthService/Check',
    new \Contracts\Gen\Common\V1\HealthCheckRequest(),
    \Contracts\Gen\Common\V1\HealthCheckResponse::class,
    $deadline,
);
if ($health->getStatus() !== \Contracts\Gen\Common\V1\HealthStatus::HEALTH_STATUS_SERVING) {
    fwrite(STDERR, "auth gRPC health is not SERVING" . PHP_EOL);
    exit(3);
}

// Phone login is a development-only entry point; production must reject it.
try {
    $client->call(
        '/auth.v1.AuthService/Login',
        new \Contracts\Gen\Auth\V1\LoginRequest(['phone' => '+8613800000000', 'code' => '000000']),
        \Contracts\Gen\Auth\V1\LoginResponse::class,
        $deadline,
    );
    fwrite(STDERR, "production phone login was unexpectedly served" . PHP_EOL);
    exit(4);
} catch (\Packages\GrpcCommon\GrpcCallException $e) {
    if ($e->appErrorCode !== \Contracts\Errors\ErrorCode::AUTH_UNAUTHORIZED->value) {
        fwrite(STDERR, "production phone login returned an unexpected error code" . PHP_EOL);
        exit(5);
    }
}

// The retired SMS phone-binding RPCs must never serve a caller.
$retired = [
    [
        '/auth.v1.AuthService/SendSocialPhoneCode',
        new \Contracts\Gen\Auth\V1\SendSocialPhoneCodeRequest(['bind_token' => 'readiness-probe', 'phone' => '+8613800000000']),
        \Contracts\Gen\Auth\V1\SendSocialPhoneCodeResponse::class,
    ],
    [
        '/auth.v1.AuthService/BindSocialPhone',
        new \Contracts\Gen\Auth\V1\BindSocialPhoneRequest(['bind_token' => 'readiness-probe', 'phone' => '+8613800000000', 'code' => '000000']),
        \Contracts\Gen\Auth\V1\BindSocialPhoneResponse::class,
    ],
];
foreach ($retired as [$method, $request, $responseClass]) {
    try {
        $client->call($method, $request, $responseClass, $deadline);
        fwrite(STDERR, "retired " . $method . " was unexpectedly served" . PHP_EOL);
        exit(6);
    } catch (\Packages\GrpcCommon\GrpcCallException $e) {
        $authenticated = $e->grpcStatus === \Spiral\RoadRunner\GRPC\StatusCode::UNAUTHENTICATED;
        $codeMatches = $e->appErrorCode === null
            || $e->appErrorCode === \Contracts\Errors\ErrorCode::AUTH_UNAUTHORIZED->value;
        if (!$authenticated || !$codeMatches) {
            fwrite(STDERR, "retired " . $method . " failed for an unrelated reason" . PHP_EOL);
            exit(7);
        }
    }
}

echo "SERVING" . PHP_EOL;
PHP
)"
}

# Social login is the production authentication path and needs no SMS provider,
# SMS pepper, or development SMS sender.
run_ready_case \
  production-starts-ready-without-sms \
  -e APP_ENV=production \
  -e ENABLE_DEV_LOGIN=false \
  -e SOCIAL_OAUTH_ALLOW_INSECURE_HTTP=false \
  -e ENABLE_WECHAT_LOGIN=true \
  -e ENABLE_QQ_LOGIN=true \
  -e WECHAT_APP_ID=production-readiness-smoke-app \
  -e WECHAT_APP_SECRET=production-readiness-smoke-secret \
  -e QQ_APP_ID=production-readiness-smoke-app \
  -e QQ_APP_KEY=production-readiness-smoke-key \
  -e QQ_REDIRECT_URI=https://app.example.com/callback \
  -e WECHAT_TOKEN_ENDPOINT=https://api.weixin.qq.com/sns/oauth2/access_token \
  -e QQ_TOKEN_ENDPOINT=https://graph.qq.com/oauth2.0/token \
  -e QQ_OPEN_ID_ENDPOINT=https://graph.qq.com/oauth2.0/me

# Test-only OAuth endpoint overrides must never be accepted by a production
# AuthService, even when the provider settings otherwise look valid.
run_rejection_case \
  oauth-endpoint-override-rejected-in-production \
  'OAuth endpoint overrides are only allowed in local, development, or testing environments' \
  -e APP_ENV=production \
  -e ENABLE_DEV_LOGIN=false \
  -e SOCIAL_OAUTH_ALLOW_INSECURE_HTTP=false \
  -e ENABLE_WECHAT_LOGIN=true \
  -e WECHAT_APP_ID=internal-rpc-config-smoke-app \
  -e WECHAT_APP_SECRET=internal-rpc-config-smoke-secret \
  -e WECHAT_TOKEN_ENDPOINT=http://fake-oauth:8081/sns/oauth2/access_token

run_rejection_case \
  insecure-oauth-http-rejected-in-production \
  'SOCIAL_OAUTH_ALLOW_INSECURE_HTTP is only allowed in local, development, or testing environments' \
  -e APP_ENV=production \
  -e ENABLE_DEV_LOGIN=false \
  -e SOCIAL_OAUTH_ALLOW_INSECURE_HTTP=true \
  -e WECHAT_TOKEN_ENDPOINT=https://api.weixin.qq.com/sns/oauth2/access_token \
  -e QQ_TOKEN_ENDPOINT=https://graph.qq.com/oauth2.0/token \
  -e QQ_OPEN_ID_ENDPOINT=https://graph.qq.com/oauth2.0/me
