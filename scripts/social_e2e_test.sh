#!/usr/bin/env bash
set -euo pipefail

# This suite intentionally drives the public HTTP API. The request then crosses
# the real Gateway gRPC client and AuthService worker before writing MySQL and
# Redis state. It must run with the compose `e2e` profile and a local fake OAuth
# endpoint; production OAuth/SMS providers are never contacted.

BASE_URL="${API_BASE_URL:-http://127.0.0.1:8787}"
LOGIN_CODE="${E2E_LOGIN_CODE:-123456}"
BODY_FILE="$(mktemp)"
trap 'rm -f "$BODY_FILE"' EXIT

request() {
  local expected="$1" method="$2" path="$3" payload="${4:-}" token="${5:-}"
  local headers=(-H 'Content-Type: application/json' -H "X-Request-Id: social-e2e-${RANDOM}-${RANDOM}")
  if [[ -n "$token" ]]; then
    headers+=(-H "Authorization: Bearer ${token}")
  fi

  local status
  if [[ -n "$payload" ]]; then
    status="$(curl --silent --show-error --max-time 15 -o "$BODY_FILE" -w '%{http_code}' \
      -X "$method" "${BASE_URL}${path}" "${headers[@]}" --data "$payload")"
  else
    status="$(curl --silent --show-error --max-time 15 -o "$BODY_FILE" -w '%{http_code}' \
      -X "$method" "${BASE_URL}${path}" "${headers[@]}")"
  fi
  if [[ "$status" != "$expected" ]]; then
    echo "FAILED ${method} ${path}: HTTP ${status}, expected ${expected}" >&2
    failure_summary >&2
    exit 1
  fi
  if ! php -r '$body=json_decode(file_get_contents($argv[1]), true); exit(is_array($body) && array_key_exists("code", $body) ? 0 : 1);' "$BODY_FILE"; then
    echo "FAILED ${method} ${path}: invalid API response" >&2
    failure_summary >&2
    exit 1
  fi
}

field() {
  php -r '
    $body=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $value=$body;
    foreach (explode(".", $argv[2]) as $key) {
      if (!is_array($value) || !array_key_exists($key, $value)) exit(1);
      $value=$value[$key];
    }
    if (is_bool($value)) { echo $value ? "true" : "false"; exit; }
    if (is_scalar($value)) echo (string)$value;
  ' "$BODY_FILE" "$1"
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

run_id="$(date +%s)$$"
phone_suffix="${run_id: -8}"
phone="+86138${phone_suffix}"
changed_phone="+86139${phone_suffix}"
wechat_code="wechat-transient-${run_id}"
qq_code="qq-primary-${run_id}"
qq_conflict_code="qq-conflict-${run_id}"
wechat_openid="wechat-openid-transient-${run_id}"
qq_openid="qq-openid-primary-${run_id}"
qq_conflict_openid="qq-openid-conflict-${run_id}"
device_uuid="social-e2e-device-${run_id}"
device="{\"device_uuid\":\"${device_uuid}\",\"platform\":\"ios\",\"app_version\":\"e2e\"}"

echo "Running social HTTP -> gRPC -> MySQL/Redis E2E for run ${run_id}"

# Seed the phone account through the normal HTTP -> gRPC -> UserService path.
# The first social bind below must merge into this existing User instead of
# creating a second row.
request 201 POST /api/v1/auth/login \
  "{\"phone\":\"${phone}\",\"code\":\"${LOGIN_CODE}\",\"device\":${device}}"
expect_code 0
expect_field data.is_new_user true
wechat_user_id="$(field data.user_id)"
[[ "$wechat_user_id" =~ ^[0-9]+$ ]]
assert_mysql user_db "SELECT COUNT(*) FROM users WHERE phone = '${phone}' AND deleted_at IS NULL" '1'
users_before_social="$(mysql_query user_db 'SELECT COUNT(*) FROM users' | tr -d '\r\n')"

# The first upstream call fails transiently in the fake OAuth service. Retrying
# the same public request must succeed without a local identity being created
# by the failed attempt.
request 503 POST /api/v1/auth/social/login \
  "{\"provider\":\"wechat\",\"code\":\"${wechat_code}\",\"device\":${device}}"
expect_code 1001010

request 200 POST /api/v1/auth/social/login \
  "{\"provider\":\"wechat\",\"code\":\"${wechat_code}\",\"device\":${device}}"
expect_code 0
expect_field data.state phone_binding_required
wechat_bind_token="$(field data.bind_token)"
[[ -n "$wechat_bind_token" ]]
php -r '
  $data=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR)["data"];
  foreach (["access_token", "refresh_token", "user_id"] as $key) {
    if (array_key_exists($key, $data)) exit(1);
  }
' "$BODY_FILE"
assert_mysql user_db 'SELECT COUNT(*) FROM users' "$users_before_social"

assert_mysql auth_db \
  "SELECT CONCAT(status, ':', COALESCE(user_id, 'NULL')) FROM social_identities WHERE provider = 'wechat' AND openid = '${wechat_openid}'" \
  '0:NULL'

request 200 POST /api/v1/auth/social/phone/code \
  "{\"bind_token\":\"${wechat_bind_token}\",\"phone\":\"${phone}\",\"device\":${device}}"
expect_code 0

# A failed SMS attempt is retained in Redis, allowing a subsequent correct
# code to complete the same bind session.
request 401 POST /api/v1/auth/social/phone/bind \
  "{\"bind_token\":\"${wechat_bind_token}\",\"phone\":\"${phone}\",\"code\":\"000000\",\"device\":${device}}"
expect_code 1001012

request 200 POST /api/v1/auth/social/phone/bind \
  "{\"bind_token\":\"${wechat_bind_token}\",\"phone\":\"${phone}\",\"code\":\"${LOGIN_CODE}\",\"device\":${device}}"
expect_code 0
expect_field data.is_new_user false
wechat_access_token="$(field data.access_token)"
wechat_refresh_token="$(field data.refresh_token)"
[[ -n "$wechat_user_id" && -n "$wechat_access_token" ]]

assert_mysql auth_db \
  "SELECT CONCAT(status, ':', COALESCE(user_id, 'NULL')) FROM social_identities WHERE provider = 'wechat' AND openid = '${wechat_openid}'" \
  "1:${wechat_user_id}"
assert_mysql user_db "SELECT COUNT(*) FROM users WHERE phone = '${phone}' AND deleted_at IS NULL" '1'

phone_hash="$(php -r 'echo hash("sha256", $argv[1]);' "$phone")"
wechat_identity_id="$(mysql_query auth_db "SELECT id FROM social_identities WHERE provider = 'wechat' AND openid = '${wechat_openid}'" | tr -d '\r\n')"
assert_redis 0 EXISTS "auth:sms:social_bind_phone:${wechat_identity_id}:${phone_hash}"

# A consumed bind token cannot be replayed, while the now-active provider can
# retry OAuth login and receives a normal session for the same local user.
request 401 POST /api/v1/auth/social/phone/bind \
  "{\"bind_token\":\"${wechat_bind_token}\",\"phone\":\"${phone}\",\"code\":\"${LOGIN_CODE}\",\"device\":${device}}"
expect_code 1001011

request 200 POST /api/v1/auth/social/login \
  "{\"provider\":\"wechat\",\"code\":\"${wechat_code}\",\"device\":${device}}"
expect_code 0
expect_field data.state authenticated
expect_field data.user_id "$wechat_user_id"

# A second provider binding the same verified phone must merge into the same
# User while keeping the provider identities separate.
request 200 POST /api/v1/auth/social/login \
  "{\"provider\":\"qq\",\"code\":\"${qq_code}\",\"device\":${device}}"
expect_code 0
expect_field data.state phone_binding_required
qq_bind_token="$(field data.bind_token)"

assert_mysql auth_db \
  "SELECT CONCAT(status, ':', COALESCE(user_id, 'NULL')) FROM social_identities WHERE provider = 'qq' AND openid = '${qq_openid}'" \
  '0:NULL'

request 200 POST /api/v1/auth/social/phone/code \
  "{\"bind_token\":\"${qq_bind_token}\",\"phone\":\"${phone}\",\"device\":${device}}"
expect_code 0
request 200 POST /api/v1/auth/social/phone/bind \
  "{\"bind_token\":\"${qq_bind_token}\",\"phone\":\"${phone}\",\"code\":\"${LOGIN_CODE}\",\"device\":${device}}"
expect_code 0
expect_field data.is_new_user false
expect_field data.user_id "$wechat_user_id"

assert_mysql auth_db \
  "SELECT CONCAT(status, ':', COALESCE(user_id, 'NULL')) FROM social_identities WHERE provider = 'qq' AND openid = '${qq_openid}'" \
  "1:${wechat_user_id}"
assert_mysql user_db "SELECT COUNT(*) FROM users WHERE phone = '${phone}' AND deleted_at IS NULL" '1'
assert_mysql auth_db "SELECT COUNT(*) FROM social_identities WHERE user_id = '${wechat_user_id}' AND status = 1" '2'

# Exercise two conflict branches through the public flow: a phone change is
# rejected before SMS consumption, and binding a second QQ identity to the
# same user is rejected by the provider uniqueness constraint.
request 200 POST /api/v1/auth/social/login \
  "{\"provider\":\"qq\",\"code\":\"${qq_conflict_code}\",\"device\":${device}}"
expect_code 0
expect_field data.state phone_binding_required
qq_conflict_bind_token="$(field data.bind_token)"

request 200 POST /api/v1/auth/social/phone/code \
  "{\"bind_token\":\"${qq_conflict_bind_token}\",\"phone\":\"${phone}\",\"device\":${device}}"
expect_code 0
request 409 POST /api/v1/auth/social/phone/bind \
  "{\"bind_token\":\"${qq_conflict_bind_token}\",\"phone\":\"${changed_phone}\",\"code\":\"${LOGIN_CODE}\",\"device\":${device}}"
expect_code 1001016

request 409 POST /api/v1/auth/social/phone/bind \
  "{\"bind_token\":\"${qq_conflict_bind_token}\",\"phone\":\"${phone}\",\"code\":\"${LOGIN_CODE}\",\"device\":${device}}"
expect_code 1001014
assert_mysql auth_db \
  "SELECT CONCAT(status, ':', COALESCE(user_id, 'NULL')) FROM social_identities WHERE provider = 'qq' AND openid = '${qq_conflict_openid}'" \
  '0:NULL'

# Two users using the same physical installation must keep independent
# device IDs and revocation keys, including after one user's logout.
request 201 POST /api/v1/auth/login \
  "{\"phone\":\"${changed_phone}\",\"code\":\"${LOGIN_CODE}\",\"device\":${device}}"
expect_code 0
other_user_id="$(field data.user_id)"
other_access_token="$(field data.access_token)"
other_refresh_token="$(field data.refresh_token)"
[[ "$other_user_id" =~ ^[0-9]+$ ]]
[[ "$other_user_id" != "$wechat_user_id" ]]
assert_mysql auth_db \
  "SELECT COUNT(DISTINCT id) FROM devices WHERE device_uuid = '${device_uuid}' AND user_id IN ('${wechat_user_id}', '${other_user_id}')" '2'

request 200 GET /api/v1/me '' "$wechat_access_token"
expect_field data.user.id "$wechat_user_id"
request 200 GET /api/v1/me '' "$other_access_token"
expect_field data.user.id "$other_user_id"

# Exercise the authoritative user lookup with a JWT issued before the state
# changed. These IDs belong only to accounts created by this test run.
mysql_query user_db "UPDATE users SET status = 0 WHERE id = '${wechat_user_id}'" >/dev/null
request 401 GET /api/v1/me '' "$wechat_access_token"
mysql_query user_db "UPDATE users SET status = 1 WHERE id = '${wechat_user_id}'" >/dev/null
request 200 GET /api/v1/me '' "$wechat_access_token"
mysql_query user_db "UPDATE users SET deleted_at = NOW(3) WHERE id = '${wechat_user_id}'" >/dev/null
request 401 GET /api/v1/me '' "$wechat_access_token"
mysql_query user_db "UPDATE users SET deleted_at = NULL WHERE id = '${wechat_user_id}'" >/dev/null
request 200 GET /api/v1/me '' "$wechat_access_token"

request 200 POST /api/v1/auth/logout \
  "{\"refresh_token\":\"${wechat_refresh_token}\"}" "$wechat_access_token"
expect_code 0
request 401 GET /api/v1/me '' "$wechat_access_token"
request 200 GET /api/v1/me '' "$other_access_token"
expect_field data.user.id "$other_user_id"
request 200 POST /api/v1/auth/refresh "{\"refresh_token\":\"${other_refresh_token}\"}"
expect_code 0
other_refreshed_access="$(field data.access_token)"
request 200 GET /api/v1/me '' "$other_refreshed_access"
expect_field data.user.id "$other_user_id"

echo 'Social E2E passed: OAuth/SMS retries, phone merge, multi-provider, Redis consumption, bind conflicts, device isolation, and issued JWT user-state checks.'
