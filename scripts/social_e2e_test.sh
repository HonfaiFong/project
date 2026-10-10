#!/usr/bin/env bash
set -euo pipefail

# Social registration and identity-binding E2E for the SMS-free auth contract.
#
# The suite drives the public HTTP API only: every request crosses the real
# Gateway gRPC client and the AuthService/UserService RoadRunner workers before
# it reaches MySQL and Redis. It runs with the compose `e2e` profile and the
# local fake OAuth upstream; production OAuth providers are never contacted and
# no SMS provider or phone number is involved.
#
# Contract under test:
#   POST /api/v1/auth/social/login  {provider, code, device}
#     -> data.state=authenticated, HTTP 201 on the account-creating call and
#        HTTP 200 afterwards. The account is created without a phone number.
#   POST /api/v1/auth/social/bind   Bearer JWT + {provider, code}
#     -> data.user_id / data.provider for the JWT holder only.
#   POST /api/v1/auth/social/phone/code and /phone/bind are removed.

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

# A removed route is answered by the framework 404 without the API envelope.
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

# Every newly registered account gets exactly one default persona plus the two
# registration outbox events, regardless of whether the relay has published them.
assert_new_account_baseline() {
  local user_id="$1"
  assert_mysql user_db "SELECT COUNT(*) FROM personas WHERE user_id = '${user_id}' AND deleted_at IS NULL" '1'
  assert_mysql user_db "SELECT COUNT(*) FROM outbox_events WHERE event_key = '${user_id}'" '2'
}

run_id="$(date +%s)$$"
device_uuid="social-e2e-device-${run_id}"
device="{\"device_uuid\":\"${device_uuid}\",\"platform\":\"ios\",\"app_version\":\"e2e\"}"

# The fake upstream fails the first call of a "transient" code once, so the
# first WeChat login of this run also proves that a failed upstream attempt
# leaves no account behind. Every code is scoped by the run id.
wechat_code="wechat-transient-${run_id}"
wechat_openid="wechat-openid-transient-${run_id}"
second_wechat_code="wechat-primary-${run_id}b"
second_wechat_openid="wechat-openid-primary-${run_id}b"
qq_code="qq-primary-${run_id}"
qq_openid="qq-openid-primary-${run_id}"

echo "Running social HTTP -> gRPC -> MySQL/Redis E2E for run ${run_id}"

# The retired phone-binding endpoints must not exist at all.
request_without_body 404 POST /api/v1/auth/social/phone/code '{"bind_token":"social-e2e"}'
request_without_body 404 POST /api/v1/auth/social/phone/bind '{"bind_token":"social-e2e","phone":"+8613800000000","code":"123456"}'

# A transient upstream failure is reported as such and creates no account.
users_before_social="$(mysql_query user_db 'SELECT COUNT(*) FROM users' | tr -d '\r\n')"
request 503 POST /api/v1/auth/social/login "{\"provider\":\"wechat\",\"code\":\"${wechat_code}\",\"device\":${device}}"
expect_code 1001010
assert_mysql user_db 'SELECT COUNT(*) FROM users' "$users_before_social"
assert_mysql auth_db \
  "SELECT COUNT(*) FROM social_identities WHERE provider = 'wechat' AND openid = '${wechat_openid}' AND status = 1" \
  '0'

# Retrying registers the WeChat account without a phone number. This is the
# first account of the run, so the API answers 201 Created.
request 201 POST /api/v1/auth/social/login "{\"provider\":\"wechat\",\"code\":\"${wechat_code}\",\"device\":${device}}"
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

# Repeating the same provider login reuses the account and its identity, and
# answers 200 instead of 201.
users_after_registration="$(mysql_query user_db 'SELECT COUNT(*) FROM users' | tr -d '\r\n')"
request 200 POST /api/v1/auth/social/login "{\"provider\":\"wechat\",\"code\":\"${wechat_code}\",\"device\":${device}}"
expect_code 0
expect_field data.state authenticated
expect_field data.is_new_user false
expect_field data.user_id "$user_a"
assert_mysql user_db 'SELECT COUNT(*) FROM users' "$users_after_registration"
assert_mysql auth_db \
  "SELECT COUNT(*) FROM social_identities WHERE provider = 'wechat' AND openid = '${wechat_openid}'" \
  '1'

# Binding is an authenticated action: without the JWT the request is rejected
# and a body-supplied user_id is never trusted.
request 401 POST /api/v1/auth/social/bind \
  "{\"provider\":\"qq\",\"code\":\"${qq_code}\",\"user_id\":\"${user_a}\"}"
expect_code 1001005
request 422 POST /api/v1/auth/social/bind '{"provider":"weibo","code":"social-e2e"}' "$access_a"
expect_code 1001008

# A logged-in WeChat user actively binds a QQ identity that has never logged in.
request 200 POST /api/v1/auth/social/bind "{\"provider\":\"qq\",\"code\":\"${qq_code}\"}" "$access_a"
expect_code 0
expect_field data.user_id "$user_a"
expect_field data.provider qq
assert_mysql auth_db \
  "SELECT CONCAT(status, ':', COALESCE(user_id, 'NULL')) FROM social_identities WHERE provider = 'qq' AND openid = '${qq_openid}'" \
  "1:${user_a}"
assert_mysql user_db "SELECT COUNT(*) FROM users WHERE id = '${user_a}' AND phone IS NULL" '1'
assert_mysql auth_db "SELECT COUNT(*) FROM social_identities WHERE user_id = '${user_a}' AND status = 1" '2'

# Logging in with the QQ code now authenticates the same local user.
request 200 POST /api/v1/auth/social/login "{\"provider\":\"qq\",\"code\":\"${qq_code}\",\"device\":${device}}"
expect_code 0
expect_field data.state authenticated
expect_field data.is_new_user false
expect_field data.user_id "$user_a"
qq_access_a="$(field data.access_token)"
request 200 GET /api/v1/me '' "$qq_access_a"
expect_field data.user.id "$user_a"

# A QQ identity that was never bound registers its own phone-less account.
qq_first_code="qq-primary-${run_id}c"
qq_first_openid="qq-openid-primary-${run_id}c"
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
request 200 POST /api/v1/auth/social/login "{\"provider\":\"qq\",\"code\":\"${qq_first_code}\",\"device\":${device}}"
expect_code 0
expect_field data.is_new_user false
expect_field data.user_id "$user_c"

# Two authorization codes for one QQ identity that arrive together must still
# create exactly one account: the fake upstream maps both nonces to one openid.
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
  # Only code/message are printed: the response bodies carry session tokens.
  ( BODY_FILE="$parallel_body_one"; failure_summary >&2 ); echo >&2
  ( BODY_FILE="$parallel_body_two"; failure_summary >&2 ); echo >&2
  exit 1
fi

parallel_user_one="$(field_of "$parallel_body_one" data.user_id)"
parallel_user_two="$(field_of "$parallel_body_two" data.user_id)"
[[ -n "$parallel_user_one" && "$parallel_user_one" == "$parallel_user_two" ]]
# Exactly one of the two concurrent requests may report the account creation.
[[ "$(field_of "$parallel_body_one" data.is_new_user)" != "$(field_of "$parallel_body_two" data.is_new_user)" ]]
assert_mysql auth_db "SELECT COUNT(*) FROM social_identities WHERE provider = 'qq' AND openid = '${parallel_openid}'" '1'
parallel_identity_id="$(mysql_query auth_db "SELECT id FROM social_identities WHERE provider = 'qq' AND openid = '${parallel_openid}'" | tr -d '\r\n')"
assert_mysql user_db "SELECT COUNT(*) FROM users WHERE social_registration_key = 'social:${parallel_identity_id}'" '1'
assert_new_account_baseline "$parallel_user_one"

# A second user (same installation) cannot steal the bound QQ identity, and the
# identity keeps authenticating its owner.
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

request 409 POST /api/v1/auth/social/bind "{\"provider\":\"qq\",\"code\":\"${qq_code}\"}" "$access_b"
expect_code 1001015
assert_mysql auth_db \
  "SELECT CONCAT(status, ':', COALESCE(user_id, 'NULL')) FROM social_identities WHERE provider = 'qq' AND openid = '${qq_openid}'" \
  "1:${user_a}"
request 200 POST /api/v1/auth/social/login "{\"provider\":\"qq\",\"code\":\"${qq_code}\",\"device\":${device}}"
expect_code 0
expect_field data.user_id "$user_a"

# Refresh, then logout: revocation is scoped to the logged-out user's device.
request 200 POST /api/v1/auth/refresh "{\"refresh_token\":\"${refresh_a}\"}"
expect_code 0
refreshed_access_a="$(field data.access_token)"
refreshed_refresh_a="$(field data.refresh_token)"
request 200 GET /api/v1/me '' "$refreshed_access_a"
expect_field data.user.id "$user_a"

# Both users share one installation, yet keep independent device rows.
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

# A newly issued session for the same provider identity authenticates again,
# and an authoritative ban or delete rejects that still-valid JWT.
request 200 POST /api/v1/auth/social/login "{\"provider\":\"qq\",\"code\":\"${qq_code}\",\"device\":${device}}"
expect_code 0
expect_field data.user_id "$user_a"
banned_access_a="$(field data.access_token)"

mysql_query user_db "UPDATE users SET status = 0 WHERE id = '${user_a}'" >/dev/null
request 401 GET /api/v1/me '' "$banned_access_a"
# A banned account cannot open a new session or register a replacement account.
users_during_ban="$(mysql_query user_db 'SELECT COUNT(*) FROM users' | tr -d '\r\n')"
request 403 POST /api/v1/auth/social/login "{\"provider\":\"qq\",\"code\":\"${qq_code}\",\"device\":${device}}"
expect_code 2001003
assert_mysql user_db 'SELECT COUNT(*) FROM users' "$users_during_ban"
mysql_query user_db "UPDATE users SET status = 1 WHERE id = '${user_a}'" >/dev/null
request 200 GET /api/v1/me '' "$banned_access_a"
expect_field data.user.id "$user_a"
mysql_query user_db "UPDATE users SET deleted_at = NOW(3) WHERE id = '${user_a}'" >/dev/null
request 401 GET /api/v1/me '' "$banned_access_a"
users_during_delete="$(mysql_query user_db 'SELECT COUNT(*) FROM users' | tr -d '\r\n')"
request 404 POST /api/v1/auth/social/login "{\"provider\":\"qq\",\"code\":\"${qq_code}\",\"device\":${device}}"
expect_code 2001001
assert_mysql user_db 'SELECT COUNT(*) FROM users' "$users_during_delete"
mysql_query user_db "UPDATE users SET deleted_at = NULL WHERE id = '${user_a}'" >/dev/null
request 200 GET /api/v1/me '' "$banned_access_a"
expect_field data.user.id "$user_a"

echo 'Social E2E passed: SMS-free first registration, repeat reuse, active bind, provider login identity, bind conflict, refresh/logout device isolation, and issued JWT user-state checks.'
