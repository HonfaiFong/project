#!/usr/bin/env bash
# Verify that access-token and device revocation records survive a Redis
# process/container restart with the compose persistence settings.
#
# This is intentionally a runtime test: the assertions read Redis after the
# restart and evaluate the same deny predicate used by the revocation store.
# WAITAOF makes the test wait for the local AOF fsync before Redis is stopped.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${COMPOSE_FILE:-${ROOT_DIR}/compose.yaml}"
COMPOSE_ENV_FILE="${COMPOSE_ENV_FILE:-${ROOT_DIR}/.env}"
COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-project}"

if [[ ! -f "$COMPOSE_ENV_FILE" ]]; then
  echo "Redis persistence test requires an env file: ${COMPOSE_ENV_FILE}" >&2
  echo "Set COMPOSE_ENV_FILE=.env.example for a local compose-only check." >&2
  exit 2
fi

compose() {
  docker compose \
    --project-name "$COMPOSE_PROJECT_NAME" \
    --env-file "$COMPOSE_ENV_FILE" \
    --file "$COMPOSE_FILE" \
    "$@"
}

redis_cli() {
  compose exec --no-TTY redis redis-cli --raw "$@"
}

revocation_denied() {
  local token_revoked="$1"
  local device_id="$2"
  local device_revoked_at="$3"
  local issued_at="$4"

  if [[ "$token_revoked" == "1" ]]; then
    return 0
  fi
  if [[ -n "$device_id" && "$device_revoked_at" =~ ^[0-9]+$ ]] && (( issued_at <= device_revoked_at )); then
    return 0
  fi
  return 1
}

started_by_test=0
redis_ready=0
token_key=""
device_key=""

cleanup() {
  local status=$?
  set +e
  if [[ "$redis_ready" == "1" ]]; then
    if [[ -n "$token_key" ]]; then
      redis_cli DEL "$token_key" >/dev/null 2>&1
    fi
    if [[ -n "$device_key" ]]; then
      redis_cli DEL "$device_key" >/dev/null 2>&1
    fi
  fi
  if [[ "$started_by_test" == "1" ]]; then
    compose stop redis >/dev/null 2>&1
  fi
  exit "$status"
}
trap cleanup EXIT

if [[ -z "$(compose ps --status running --quiet redis 2>/dev/null)" ]]; then
  compose up --detach redis
  started_by_test=1
fi

for attempt in $(seq 1 30); do
  if [[ "$(redis_cli PING 2>/dev/null || true)" == "PONG" ]]; then
    redis_ready=1
    break
  fi
  sleep 1
done
if [[ "$redis_ready" != "1" ]]; then
  echo "Redis did not become ready within 30 seconds." >&2
  exit 1
fi

appendonly="$(redis_cli CONFIG GET appendonly | sed -n '2p' | tr -d '\r')"
appendfsync="$(redis_cli CONFIG GET appendfsync | sed -n '2p' | tr -d '\r')"
if [[ "$appendonly" != "yes" || "$appendfsync" != "everysec" ]]; then
  echo "Redis persistence contract failed: appendonly=${appendonly:-<missing>} appendfsync=${appendfsync:-<missing>}" >&2
  exit 1
fi

run_id="$(date +%s)-$$-${RANDOM}"
token_hash="$(printf '%s' "access-token-${run_id}" | sha256sum | awk '{print $1}')"
device_id="device-${run_id}"
token_key="auth:access-token-revoked:${token_hash}"
device_key="auth:access-device-revoked:${device_id}"
other_token_hash="$(printf '%s' "different-access-token-${run_id}" | sha256sum | awk '{print $1}')"
other_token_key="auth:access-token-revoked:${other_token_hash}"
issued_at=$(( $(date +%s) - 1 ))
device_revoked_at="$(date +%s)"
ttl_seconds=600

if [[ "$(redis_cli SETEX "$token_key" "$ttl_seconds" 1 | tr -d '\r')" != "OK" ]]; then
  echo "Failed to write the access-token revocation record." >&2
  exit 1
fi
if [[ "$(redis_cli SETEX "$device_key" "$ttl_seconds" "$device_revoked_at" | tr -d '\r')" != "OK" ]]; then
  echo "Failed to write the device revocation record." >&2
  exit 1
fi

# Redis 7.2+ waits for the local AOF fsync. A plain sleep would make this test
# race the everysec policy and could report a false failure or false success.
if [[ "$(redis_cli WAITAOF 1 0 5000 | sed -n '1p' | tr -d '\r')" != "1" ]]; then
  echo "Redis did not acknowledge a local AOF fsync (WAITAOF)." >&2
  exit 1
fi

compose restart redis >/dev/null
redis_ready=0
for attempt in $(seq 1 30); do
  if [[ "$(redis_cli PING 2>/dev/null || true)" == "PONG" ]]; then
    redis_ready=1
    break
  fi
  sleep 1
done
if [[ "$redis_ready" != "1" ]]; then
  echo "Redis did not become ready after restart." >&2
  exit 1
fi

persisted_token="$(redis_cli GET "$token_key" | tr -d '\r')"
persisted_device="$(redis_cli GET "$device_key" | tr -d '\r')"
token_ttl="$(redis_cli TTL "$token_key" | tr -d '\r')"
device_ttl="$(redis_cli TTL "$device_key" | tr -d '\r')"
if [[ "$persisted_token" != "1" || ! "$token_ttl" =~ ^[0-9]+$ || "$token_ttl" -le 0 ]]; then
  echo "Access-token revocation record was not recovered with a positive TTL." >&2
  exit 1
fi
if [[ -z "$persisted_device" || ! "$persisted_device" =~ ^[0-9]+$ || "$persisted_device" -le 0 \
  || ! "$device_ttl" =~ ^[0-9]+$ || "$device_ttl" -le 0 ]]; then
  echo "Revocation records were not recovered from AOF after restart." >&2
  exit 1
fi

if ! revocation_denied "$persisted_token" "" "" "$issued_at"; then
  echo "Token-only revocation assertion failed: token marker did not deny access without a device key." >&2
  exit 1
fi
echo "Token-only revocation assertion passed after restart (value=1, ttl=${token_ttl}s)."

# A different token has no token-revocation key. Its denial must come solely
# from the recovered device timestamp, proving both branches independently.
other_token_value="$(redis_cli GET "$other_token_key" | tr -d '\r')"
if [[ -n "$other_token_value" || "$(redis_cli EXISTS "$other_token_key" | tr -d '\r')" != "0" ]]; then
  echo "Different token unexpectedly has a token-revocation marker." >&2
  exit 1
fi
if ! revocation_denied "$other_token_value" "$device_id" "$persisted_device" "$issued_at"; then
  echo "Device-only revocation assertion failed: recovered device timestamp did not deny access." >&2
  exit 1
fi
echo "Device-only revocation assertion passed after restart (value=${persisted_device}, ttl=${device_ttl}s)."
