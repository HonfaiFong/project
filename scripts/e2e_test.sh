#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${API_BASE_URL:-http://127.0.0.1:8787}"
BODY_FILE="$(mktemp)"
trap 'rm -f "$BODY_FILE"' EXIT

request() {
  local expected="$1" method="$2" path="$3" payload="${4:-}" token="${5:-}"
  local headers=(-H 'Content-Type: application/json')
  if [[ -n "$token" ]]; then
    headers+=(-H "Authorization: Bearer ${token}")
  fi
  local status
  status="$(curl --silent --show-error --max-time 10 -o "$BODY_FILE" -w '%{http_code}' \
    -X "$method" "${BASE_URL}${path}" "${headers[@]}" ${payload:+--data "$payload"})"
  if [[ "$status" != "$expected" ]]; then
    echo "FAILED ${method} ${path}: HTTP ${status}, expected ${expected}" >&2
    exit 1
  fi
  if ! php -r '$body=json_decode(file_get_contents($argv[1]), true); exit(is_array($body) && isset($body["code"]) ? 0 : 1);' "$BODY_FILE"; then
    echo "FAILED ${method} ${path}: invalid API response" >&2
    exit 1
  fi
}

field() {
  php -r '
    $body=json_decode(file_get_contents($argv[1]), true);
    $value=$body;
    foreach (explode(".", $argv[2]) as $key) {
      if (!is_array($value) || !array_key_exists($key, $value)) exit(1);
      $value=$value[$key];
    }
    if (is_scalar($value)) echo $value;
  ' "$BODY_FILE" "$1"
}

expect_code() {
  local actual
  actual="$(field code)"
  if [[ "$actual" != "$1" ]]; then
    echo "FAILED: business code ${actual}, expected $1" >&2
    exit 1
  fi
}

stamp="$(date +%s)-${RANDOM}"

request 200 GET /health
expect_code 0

request 401 POST /api/v1/auth/social/login '{"provider":"wechat","code":"invalid","device":{"device_uuid":"e2e-invalid","platform":"ios"}}'

request 201 POST /api/v1/auth/social/login "{\"provider\":\"wechat\",\"code\":\"wechat-primary-${stamp}a\",\"device\":{\"device_uuid\":\"e2e-a-${stamp}\",\"platform\":\"ios\"}}"
expect_code 0
access_a="$(field data.access_token)"
refresh_a="$(field data.refresh_token)"
user_a="$(field data.user_id)"
[[ -n "$access_a" && -n "$refresh_a" && -n "$user_a" ]]

request 200 GET /api/v1/me '' "$access_a"
expect_code 0
[[ "$(field data.user.id)" == "$user_a" ]]

request 201 POST /api/v1/personas '{"type":1,"nickname":"NightWatcher"}' "$access_a"
expect_code 0
persona_a="$(field data.id)"
[[ -n "$persona_a" ]]

request 200 GET /api/v1/personas '' "$access_a"
expect_code 0
php -r '$body=json_decode(file_get_contents($argv[1]), true); $ids=array_column($body["data"]["personas"] ?? [], "id"); exit(in_array($argv[2], array_map("strval", $ids), true) ? 0 : 1);' "$BODY_FILE" "$persona_a"

request 200 GET "/api/v1/personas/${persona_a}" '' "$access_a"
expect_code 0
php -r '$body=json_decode(file_get_contents($argv[1]), true); exit(isset($body["data"]["user_id"]) ? 1 : 0);' "$BODY_FILE"

request 201 POST /api/v1/auth/social/login "{\"provider\":\"wechat\",\"code\":\"wechat-primary-${stamp}b\",\"device\":{\"device_uuid\":\"e2e-b-${stamp}\",\"platform\":\"ios\"}}"
expect_code 0
access_b="$(field data.access_token)"

request 403 POST /api/v1/ws/ticket "{\"persona_id\":\"${persona_a}\"}" "$access_b"
request 200 POST /api/v1/ws/ticket "{\"persona_id\":\"${persona_a}\"}" "$access_a"
expect_code 0
[[ -n "$(field data.ticket)" ]]

request 200 POST /api/v1/auth/refresh "{\"refresh_token\":\"${refresh_a}\"}"
expect_code 0
new_access="$(field data.access_token)"
new_refresh="$(field data.refresh_token)"
[[ -n "$new_access" && -n "$new_refresh" ]]

request 200 POST /api/v1/auth/logout "{\"refresh_token\":\"${new_refresh}\"}" "$new_access"
expect_code 0
request 401 GET /api/v1/me '' "$new_access"

echo 'Phase 1 E2E passed: login, profile, persona privacy, ticket ownership, refresh, logout revocation.'
