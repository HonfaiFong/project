#!/usr/bin/env bash
set -euo pipefail

# 生产环境 AuthService 启动检查。
#
#   1. 生产工作进程在启用社交登录且无短信配置时，须正常启动并报告就绪。以工作进程自身的启动日志行为就绪依据；提前
#   退出或超时均判为失败。
#   2. 仅用于测试的 OAuth 端点覆盖和不安全 OAuth HTTP 配置，须在 RoadRunner 工作进程启动前被拒绝。

READY_MARKER='AuthService started with RoadRunner gRPC worker'
READY_TIMEOUT_SECONDS="${AUTH_READY_TIMEOUT_SECONDS:-120}"
PROBE_WINDOW_SECONDS="${AUTH_PROBE_WINDOW_SECONDS:-60}"
PROBE_RPC_DEADLINE_MS="${AUTH_PROBE_RPC_DEADLINE_MS:-30000}"
# 按本轮运行隔离，避免并发运行删除彼此的容器。
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

  # 容器运行真实生产命令，由 RoadRunner 监管 AuthService 工作进程；下方探测因此访问实际提供服务的 gRPC 端口。
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

# 通过 gRPC 探测运行中的 AuthService 容器。仅此探测进程覆盖 SERVICE_NAME：AuthService 授权器要求网关调用方身份，
# 且不会输出服务令牌。
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

// 手机登录仅用于开发环境，生产环境必须拒绝。
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

// 已废弃的短信手机号绑定 RPC 不得处理任何调用方请求。
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

# 社交登录是生产认证路径，无须短信提供方、短信 pepper 或开发短信发送器。
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

# 生产 AuthService 不能接受仅用于测试的 OAuth 端点覆盖，即使其他提供方配置看似有效。
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
