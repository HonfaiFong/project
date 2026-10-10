#!/usr/bin/env bash
set -euo pipefail

# 面向无短信认证协议的社交注册和身份绑定端到端测试。
#
# 测试仅调用公共 HTTP API：每个请求通过真实网关 gRPC 客户端及 AuthService/UserService RoadRunner 工作进程，再访
# 问 MySQL 和 Redis。使用 compose 的 `e2e` 配置和本地模拟 OAuth 上游，不连接生产 OAuth 提供方，也不涉及短信提供
# 方或手机号。
#
# 被测协议：
#   POST /api/v1/auth/social/login  {provider, code, device}
#     -> data.state=authenticated，创建账户的调用返回 HTTP 201，后续调用返回 HTTP 200；创建账户时无须手机号。
#   POST /api/v1/auth/social/bind   Bearer JWT + {provider, code}
#     -> data.user_id / data.provider 仅对应 JWT 持有者。
#   POST /api/v1/auth/social/phone/code 和 /phone/bind 已移除。

BASE_URL="${API_BASE_URL:-http://127.0.0.1:8787}"
BODY_FILE="$(mktemp)"
trap 'rm -f "$BODY_FILE"' EXIT

http_call() {
  local method="$1" path="$2" payload="${3:-}" token="${4:-}"
  local headers=(-H 'Content-Type: application/json' -H "X-Request-Id: social-e2e-${RANDOM}-${RANDOM}")
  if [[ -n "$token" ]]; then
    headers+=(-H "Authorization: Bearer ${token}")
  fi

  if [[ -n "$payload" ]]; then
    curl --silent --show-error --max-time 15 -o "$BODY_FILE" -w '%{http_code}' \
      -X "$method" "${BASE_URL}${path}" "${headers[@]}" --data "$payload"
  else
    curl --silent --show-error --max-time 15 -o "$BODY_FILE" -w '%{http_code}' \
      -X "$method" "${BASE_URL}${path}" "${headers[@]}"
  fi
}

assert_http_status() {
  local method="$1" path="$2" actual="$3" expected="$4"
  if [[ "$actual" != "$expected" ]]; then
    echo "FAILED ${method} ${path}: HTTP ${actual}, expected ${expected}" >&2
    failure_summary >&2
    exit 1
  fi
}

request() {
  local expected="$1" method="$2" path="$3" payload="${4:-}" token="${5:-}"
  local status
  status="$(http_call "$method" "$path" "$payload" "$token")"
  assert_http_status "$method" "$path" "$status" "$expected"
  if ! php -r '$body=json_decode(file_get_contents($argv[1]), true); exit(is_array($body) && array_key_exists("code", $body) ? 0 : 1);' "$BODY_FILE"; then
    echo "FAILED ${method} ${path}: invalid API response" >&2
    failure_summary >&2
    exit 1
  fi
}

# 已移除的路由由框架返回 404，不包含 API 响应封装。
request_without_body() {
  local expected="$1" method="$2" path="$3" payload="${4:-}" token="${5:-}"
  local status
  status="$(http_call "$method" "$path" "$payload" "$token")"
  assert_http_status "$method" "$path" "$status" "$expected"
}

field_of() {
  php -r '
    $body=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $value=$body;
    foreach (explode(".", $argv[2]) as $key) {
      if (!is_array($value) || !array_key_exists($key, $value)) exit(1);
      $value=$value[$key];
    }
    if (is_bool($value)) { echo $value ? "true" : "false"; exit; }
    if (is_scalar($value)) echo (string)$value;
  ' "$1" "$2"
}

field() {
  field_of "$BODY_FILE" "$1"
}

failure_summary() {
  php -r '
    $body=json_decode(file_get_contents($argv[1]), true);
    if (!is_array($body)) { echo "<invalid-json>"; exit; }
    echo json_encode([
      "code" => $body["code"] ?? null,
      "message" => $body["message"] ?? null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  ' "$BODY_FILE"
}

expect_code() {
  local expected="$1" actual
  actual="$(field code)"
  if [[ "$actual" != "$expected" ]]; then
    echo "FAILED: business code ${actual}, expected ${expected}" >&2
    failure_summary >&2
    exit 1
  fi
}

expect_field() {
  local path="$1" expected="$2" actual
  actual="$(field "$path")"
  if [[ "$actual" != "$expected" ]]; then
    echo "FAILED: ${path}=${actual}, expected ${expected}" >&2
    failure_summary >&2
    exit 1
  fi
}

mysql_query() {
  local database="$1" sql="$2"
  docker compose exec -T mysql sh -ec \
    'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B "$1" -e "$2"' \
    social-e2e-mysql "$database" "$sql"
}

redis_query() {
  docker compose exec -T redis redis-cli --raw "$@"
}

assert_mysql() {
  local database="$1" sql="$2" expected="$3" actual
  actual="$(mysql_query "$database" "$sql" | tr -d '\r\n')"
  if [[ "$actual" != "$expected" ]]; then
    echo "FAILED MySQL assertion: got '${actual}', expected '${expected}'" >&2
    exit 1
  fi
}

assert_redis() {
  local expected="$1"; shift
  local actual
  actual="$(redis_query "$@" | tr -d '\r\n')"
  if [[ "$actual" != "$expected" ]]; then
    echo "FAILED Redis assertion: got '${actual}', expected '${expected}' ($*)" >&2
    exit 1
  fi
}

# 每个新账户均有且仅有一个默认人格及两条注册 Outbox 事件，无论中继是否已发布事件。
assert_new_account_baseline() {
  local user_id="$1"
  assert_mysql user_db "SELECT COUNT(*) FROM personas WHERE user_id = '${user_id}' AND deleted_at IS NULL" '1'
  assert_mysql user_db "SELECT COUNT(*) FROM outbox_events WHERE event_key = '${user_id}'" '2'
}

run_id="$(date +%s)$$"
device_uuid="social-e2e-device-${run_id}"
device="{\"device_uuid\":\"${device_uuid}\",\"platform\":\"ios\",\"app_version\":\"e2e\"}"

# 模拟上游会让“transient”授权码的首次调用失败，因此本轮首次微信登录也验证上游失败不会残留账户。模拟上游的授权码
# 只能使用一次，兑换失败也会使其失效；下方每次兑换携带不同“~nonce”，身份部分仍由本轮运行 ID 隔离，实现不同授权码
# 对应同一外部身份。
wechat_identity="transient-${run_id}"
wechat_openid="wechat-openid-${wechat_identity}"
wechat_code_fail="wechat-${wechat_identity}~1"
wechat_code_register="wechat-${wechat_identity}~2"
wechat_code_repeat="wechat-${wechat_identity}~3"
second_wechat_code="wechat-primary-${run_id}b"
second_wechat_openid="wechat-openid-primary-${run_id}b"
qq_identity="primary-${run_id}"
qq_openid="qq-openid-${qq_identity}"
qq_code_bind="qq-${qq_identity}~1"
qq_code_login="qq-${qq_identity}~2"
qq_code_conflict="qq-${qq_identity}~3"
qq_code_owner="qq-${qq_identity}~4"
qq_code_pre_ban="qq-${qq_identity}~5"
qq_code_banned="qq-${qq_identity}~6"
qq_code_deleted="qq-${qq_identity}~7"
qq_first_identity="primary-${run_id}c"
qq_first_openid="qq-openid-${qq_first_identity}"
qq_first_code="qq-${qq_first_identity}~1"
qq_first_code_repeat="qq-${qq_first_identity}~2"

echo "Running social HTTP -> gRPC -> MySQL/Redis E2E for run ${run_id}"

# 已废弃的手机号绑定端点必须完全不存在。
request_without_body 404 POST /api/v1/auth/social/phone/code '{"bind_token":"social-e2e"}'
request_without_body 404 POST /api/v1/auth/social/phone/bind '{"bind_token":"social-e2e","phone":"+8613800000000","code":"123456"}'

# 临时上游失败须按实际故障报告，且不创建账户。已失效授权码保持失效；像真实一次性授权码一样，重放必须被拒绝，客户
# 端须获取新授权码才能恢复。
users_before_social="$(mysql_query user_db 'SELECT COUNT(*) FROM users' | tr -d '\r\n')"
request 503 POST /api/v1/auth/social/login "{\"provider\":\"wechat\",\"code\":\"${wechat_code_fail}\",\"device\":${device}}"
expect_code 1001010
assert_mysql user_db 'SELECT COUNT(*) FROM users' "$users_before_social"
assert_mysql auth_db \
  "SELECT COUNT(*) FROM social_identities WHERE provider = 'wechat' AND openid = '${wechat_openid}' AND status = 1" \
  '0'
request 401 POST /api/v1/auth/social/login "{\"provider\":\"wechat\",\"code\":\"${wechat_code_fail}\",\"device\":${device}}"
expect_code 1001009

# 使用同一外部身份的新授权码恢复时，注册无手机号的微信账户，不创建重复用户。这是本轮首个账户，因此 API 返回 201
# Created。
request 201 POST /api/v1/auth/social/login "{\"provider\":\"wechat\",\"code\":\"${wechat_code_register}\",\"device\":${device}}"
expect_code 0
expect_field data.state authenticated
expect_field data.is_new_user true
user_a="$(field data.user_id)"
access_a="$(field data.access_token)"
refresh_a="$(field data.refresh_token)"
[[ "$user_a" =~ ^[0-9]+$ ]]
[[ -n "$access_a" && -n "$refresh_a" ]]
assert_mysql user_db "SELECT COUNT(*) FROM users WHERE id = '${user_a}' AND phone IS NULL AND deleted_at IS NULL" '1'
assert_mysql user_db "SELECT register_channel FROM users WHERE id = '${user_a}'" 'wechat'
assert_mysql auth_db \
  "SELECT CONCAT(status, ':', COALESCE(user_id, 'NULL')) FROM social_identities WHERE provider = 'wechat' AND openid = '${wechat_openid}'" \
  "1:${user_a}"
assert_new_account_baseline "$user_a"

# 再次使用同一身份的新授权码访问时，复用账户及身份，返回 200 而非 201。
users_after_registration="$(mysql_query user_db 'SELECT COUNT(*) FROM users' | tr -d '\r\n')"
request 200 POST /api/v1/auth/social/login "{\"provider\":\"wechat\",\"code\":\"${wechat_code_repeat}\",\"device\":${device}}"
expect_code 0
expect_field data.state authenticated
expect_field data.is_new_user false
expect_field data.user_id "$user_a"
assert_mysql user_db 'SELECT COUNT(*) FROM users' "$users_after_registration"
assert_mysql auth_db \
  "SELECT COUNT(*) FROM social_identities WHERE provider = 'wechat' AND openid = '${wechat_openid}'" \
  '1'

# 绑定须先认证：无 JWT 时请求被拒绝，且不信任请求体提供的 user_id。这些拒绝不消耗授权码，因为请求尚未到达 OAuth
# 上游。
request 401 POST /api/v1/auth/social/bind \
  "{\"provider\":\"qq\",\"code\":\"${qq_code_bind}\",\"user_id\":\"${user_a}\"}"
expect_code 1001005
request 422 POST /api/v1/auth/social/bind '{"provider":"weibo","code":"social-e2e"}' "$access_a"
expect_code 1001008

# 已登录微信用户主动绑定从未登录过的 QQ 身份。
request 200 POST /api/v1/auth/social/bind "{\"provider\":\"qq\",\"code\":\"${qq_code_bind}\"}" "$access_a"
expect_code 0
expect_field data.user_id "$user_a"
expect_field data.provider qq
assert_mysql auth_db \
  "SELECT CONCAT(status, ':', COALESCE(user_id, 'NULL')) FROM social_identities WHERE provider = 'qq' AND openid = '${qq_openid}'" \
  "1:${user_a}"
assert_mysql user_db "SELECT COUNT(*) FROM users WHERE id = '${user_a}' AND phone IS NULL" '1'
assert_mysql auth_db "SELECT COUNT(*) FROM social_identities WHERE user_id = '${user_a}' AND status = 1" '2'

# 使用已绑定身份的新 QQ 授权码登录时，认证为同一本地用户。
request 200 POST /api/v1/auth/social/login "{\"provider\":\"qq\",\"code\":\"${qq_code_login}\",\"device\":${device}}"
expect_code 0
expect_field data.state authenticated
expect_field data.is_new_user false
expect_field data.user_id "$user_a"
qq_access_a="$(field data.access_token)"
request 200 GET /api/v1/me '' "$qq_access_a"
expect_field data.user.id "$user_a"

# 从未绑定的 QQ 身份会注册独立的无手机号账户。
request 201 POST /api/v1/auth/social/login "{\"provider\":\"qq\",\"code\":\"${qq_first_code}\",\"device\":${device}}"
expect_code 0
expect_field data.state authenticated
expect_field data.is_new_user true
user_c="$(field data.user_id)"
[[ "$user_c" =~ ^[0-9]+$ ]]
[[ "$user_c" != "$user_a" ]]
assert_mysql user_db "SELECT COUNT(*) FROM users WHERE id = '${user_c}' AND phone IS NULL AND register_channel = 'qq'" '1'
assert_mysql auth_db \
  "SELECT CONCAT(status, ':', COALESCE(user_id, 'NULL')) FROM social_identities WHERE provider = 'qq' AND openid = '${qq_first_openid}'" \
  "1:${user_c}"
assert_new_account_baseline "$user_c"
request 200 POST /api/v1/auth/social/login "{\"provider\":\"qq\",\"code\":\"${qq_first_code_repeat}\",\"device\":${device}}"
expect_code 0
expect_field data.is_new_user false
expect_field data.user_id "$user_c"

# 同一 QQ 身份的两个授权码同时到达时，仍只能创建一个账户；模拟上游将两个随机数映射为同一 openid。
parallel_openid="qq-openid-primary-${run_id}p"
parallel_body_one="$(mktemp)"
parallel_body_two="$(mktemp)"
parallel_status_one="$(mktemp)"
parallel_status_two="$(mktemp)"
trap 'rm -f "$BODY_FILE" "$parallel_body_one" "$parallel_body_two" "$parallel_status_one" "$parallel_status_two"' EXIT

parallel_social_login() {
  local out_body="$1" out_status="$2" code="$3"
  curl --silent --show-error --max-time 15 -o "$out_body" -w '%{http_code}' \
    -X POST "${BASE_URL}/api/v1/auth/social/login" \
    -H 'Content-Type: application/json' \
    -H "X-Request-Id: social-e2e-parallel-${RANDOM}-${RANDOM}" \
    --data "{\"provider\":\"qq\",\"code\":\"${code}\",\"device\":${device}}" >"$out_status"
}

parallel_social_login "$parallel_body_one" "$parallel_status_one" "qq-primary-${run_id}p~one" &
parallel_social_login "$parallel_body_two" "$parallel_status_two" "qq-primary-${run_id}p~two" &
wait

parallel_status_code_one="$(cat "$parallel_status_one")"
parallel_status_code_two="$(cat "$parallel_status_two")"
if [[ "$parallel_status_code_one" != "200" && "$parallel_status_code_one" != "201" ]] \
  || [[ "$parallel_status_code_two" != "200" && "$parallel_status_code_two" != "201" ]]; then
  echo "FAILED: parallel social login returned HTTP ${parallel_status_code_one}/${parallel_status_code_two}" >&2
  # 仅输出 code/message；响应体含有会话令牌。
  ( BODY_FILE="$parallel_body_one"; failure_summary >&2 ); echo >&2
  ( BODY_FILE="$parallel_body_two"; failure_summary >&2 ); echo >&2
  exit 1
fi

parallel_user_one="$(field_of "$parallel_body_one" data.user_id)"
parallel_user_two="$(field_of "$parallel_body_two" data.user_id)"
[[ -n "$parallel_user_one" && "$parallel_user_one" == "$parallel_user_two" ]]
# 两个并发请求中恰有一个可以报告账户已创建。
[[ "$(field_of "$parallel_body_one" data.is_new_user)" != "$(field_of "$parallel_body_two" data.is_new_user)" ]]
assert_mysql auth_db "SELECT COUNT(*) FROM social_identities WHERE provider = 'qq' AND openid = '${parallel_openid}'" '1'
parallel_identity_id="$(mysql_query auth_db "SELECT id FROM social_identities WHERE provider = 'qq' AND openid = '${parallel_openid}'" | tr -d '\r\n')"
assert_mysql user_db "SELECT COUNT(*) FROM users WHERE social_registration_key = 'social:${parallel_identity_id}'" '1'
assert_new_account_baseline "$parallel_user_one"

# 第二个用户使用相同安装实例，也不能抢占已绑定 QQ 身份；该身份继续认证为其所属用户。
request 201 POST /api/v1/auth/social/login "{\"provider\":\"wechat\",\"code\":\"${second_wechat_code}\",\"device\":${device}}"
expect_code 0
expect_field data.state authenticated
expect_field data.is_new_user true
user_b="$(field data.user_id)"
access_b="$(field data.access_token)"
refresh_b="$(field data.refresh_token)"
[[ "$user_b" =~ ^[0-9]+$ ]]
[[ "$user_b" != "$user_a" ]]
assert_mysql user_db "SELECT COUNT(*) FROM users WHERE id = '${user_b}' AND phone IS NULL AND register_channel = 'wechat'" '1'
assert_mysql auth_db \
  "SELECT CONCAT(status, ':', COALESCE(user_id, 'NULL')) FROM social_identities WHERE provider = 'wechat' AND openid = '${second_wechat_openid}'" \
  "1:${user_b}"
assert_new_account_baseline "$user_b"

request 409 POST /api/v1/auth/social/bind "{\"provider\":\"qq\",\"code\":\"${qq_code_conflict}\"}" "$access_b"
expect_code 1001015
assert_mysql auth_db \
  "SELECT CONCAT(status, ':', COALESCE(user_id, 'NULL')) FROM social_identities WHERE provider = 'qq' AND openid = '${qq_openid}'" \
  "1:${user_a}"
request 200 POST /api/v1/auth/social/login "{\"provider\":\"qq\",\"code\":\"${qq_code_owner}\",\"device\":${device}}"
expect_code 0
expect_field data.user_id "$user_a"

# 先刷新再退出：撤销仅作用于退出用户的设备。
request 200 POST /api/v1/auth/refresh "{\"refresh_token\":\"${refresh_a}\"}"
expect_code 0
refreshed_access_a="$(field data.access_token)"
refreshed_refresh_a="$(field data.refresh_token)"
request 200 GET /api/v1/me '' "$refreshed_access_a"
expect_field data.user.id "$user_a"

# 两个用户共享一个安装实例，但保持独立设备行。
assert_mysql auth_db \
  "SELECT COUNT(DISTINCT id) FROM devices WHERE device_uuid = '${device_uuid}' AND user_id IN ('${user_a}', '${user_b}')" \
  '2'
request 200 GET /api/v1/me '' "$access_b"
expect_field data.user.id "$user_b"

request 200 POST /api/v1/auth/logout "{\"refresh_token\":\"${refreshed_refresh_a}\"}" "$refreshed_access_a"
expect_code 0
request 401 GET /api/v1/me '' "$refreshed_access_a"
request 200 GET /api/v1/me '' "$access_b"
expect_field data.user.id "$user_b"
request 200 POST /api/v1/auth/refresh "{\"refresh_token\":\"${refresh_b}\"}"
expect_code 0
refreshed_access_b="$(field data.access_token)"
request 200 GET /api/v1/me '' "$refreshed_access_b"
expect_field data.user.id "$user_b"

device_a="$(mysql_query auth_db "SELECT id FROM devices WHERE device_uuid = '${device_uuid}' AND user_id = '${user_a}'" | tr -d '\r\n')"
device_b="$(mysql_query auth_db "SELECT id FROM devices WHERE device_uuid = '${device_uuid}' AND user_id = '${user_b}'" | tr -d '\r\n')"
[[ -n "$device_a" && -n "$device_b" && "$device_a" != "$device_b" ]]
assert_redis 1 EXISTS "auth:access-device-revoked:${device_a}"
assert_redis 0 EXISTS "auth:access-device-revoked:${device_b}"
assert_redis 1 EXISTS "auth:access-token-revoked:$(php -r 'echo hash("sha256", $argv[1]);' "$refreshed_access_a")"

# 同一提供方身份的新会话可重新认证；账户被权威数据源封禁或删除后，即使 JWT 仍有效，也须拒绝认证。
request 200 POST /api/v1/auth/social/login "{\"provider\":\"qq\",\"code\":\"${qq_code_pre_ban}\",\"device\":${device}}"
expect_code 0
expect_field data.user_id "$user_a"
banned_access_a="$(field data.access_token)"

mysql_query user_db "UPDATE users SET status = 0 WHERE id = '${user_a}'" >/dev/null
request 401 GET /api/v1/me '' "$banned_access_a"
# 被封禁账户不能创建新会话，也不能注册替代账户。
users_during_ban="$(mysql_query user_db 'SELECT COUNT(*) FROM users' | tr -d '\r\n')"
request 403 POST /api/v1/auth/social/login "{\"provider\":\"qq\",\"code\":\"${qq_code_banned}\",\"device\":${device}}"
expect_code 2001003
assert_mysql user_db 'SELECT COUNT(*) FROM users' "$users_during_ban"
mysql_query user_db "UPDATE users SET status = 1 WHERE id = '${user_a}'" >/dev/null
request 200 GET /api/v1/me '' "$banned_access_a"
expect_field data.user.id "$user_a"
mysql_query user_db "UPDATE users SET deleted_at = NOW(3) WHERE id = '${user_a}'" >/dev/null
request 401 GET /api/v1/me '' "$banned_access_a"
users_during_delete="$(mysql_query user_db 'SELECT COUNT(*) FROM users' | tr -d '\r\n')"
request 404 POST /api/v1/auth/social/login "{\"provider\":\"qq\",\"code\":\"${qq_code_deleted}\",\"device\":${device}}"
expect_code 2001001
assert_mysql user_db 'SELECT COUNT(*) FROM users' "$users_during_delete"
mysql_query user_db "UPDATE users SET deleted_at = NULL WHERE id = '${user_a}'" >/dev/null
request 200 GET /api/v1/me '' "$banned_access_a"
expect_field data.user.id "$user_a"

echo 'Social E2E passed: single-use OAuth code redemption, fresh-code recovery of the same identity, SMS-free first registration, repeat reuse, active bind, provider login identity, bind conflict, refresh/logout device isolation, and issued JWT user-state checks.'
