#!/usr/bin/env bash
set -euo pipefail

run_rejection_case() {
  local name="$1"
  local expected_message="$2"
  shift 2

  local log_file
  log_file="$(mktemp)"
  trap 'rm -f "$log_file"' RETURN

  if docker compose run --rm --no-deps "$@" auth-service php /app/services/auth-service/bin/server.php >"$log_file" 2>&1; then
    echo "FAIL case=${name} expected_auth_startup_rejection"
    return 1
  fi

  if ! grep -Fq "$expected_message" "$log_file"; then
    echo "FAIL case=${name} expected_guard_message_missing"
    return 1
  fi

  echo "PASS case=${name}"
}

# A development SMS sender must never be accepted in production, even when the
# development login path itself is disabled.
run_rejection_case \
  dev-sms-rejected-in-production \
  'ENABLE_DEV_SMS is only allowed in local, development, or testing environments' \
  -e APP_ENV=production \
  -e ENABLE_DEV_LOGIN=false \
  -e ENABLE_DEV_SMS=true

# Enabling a social provider in production without a real SMS adapter must
# fail closed before the RoadRunner worker starts. Dummy OAuth values are
# test inputs only and are never printed.
run_rejection_case \
  social-login-requires-sms-adapter \
  'Social login requires a configured production SMS adapter or explicitly enabled development SMS' \
  -e APP_ENV=production \
  -e ENABLE_DEV_LOGIN=false \
  -e ENABLE_DEV_SMS=false \
  -e SMS_PROVIDER= \
  -e ENABLE_WECHAT_LOGIN=true \
  -e WECHAT_APP_ID=internal-rpc-config-smoke-app \
  -e WECHAT_APP_SECRET=internal-rpc-config-smoke-secret
