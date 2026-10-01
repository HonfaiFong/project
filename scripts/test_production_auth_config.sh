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
  -e SOCIAL_OAUTH_ALLOW_INSECURE_HTTP=false \
  -e WECHAT_TOKEN_ENDPOINT=https://api.weixin.qq.com/sns/oauth2/access_token \
  -e QQ_TOKEN_ENDPOINT=https://graph.qq.com/oauth2.0/token \
  -e QQ_OPEN_ID_ENDPOINT=https://graph.qq.com/oauth2.0/me \
  -e ENABLE_DEV_SMS=true

# Enabling a social provider in production without a real SMS adapter must
# fail closed before the RoadRunner worker starts. Dummy OAuth values are
# test inputs only and are never printed.
run_rejection_case \
  social-login-requires-sms-adapter \
  'Social login requires a configured production SMS adapter or explicitly enabled development SMS' \
  -e APP_ENV=production \
  -e ENABLE_DEV_LOGIN=false \
  -e SOCIAL_OAUTH_ALLOW_INSECURE_HTTP=false \
  -e WECHAT_TOKEN_ENDPOINT=https://api.weixin.qq.com/sns/oauth2/access_token \
  -e QQ_TOKEN_ENDPOINT=https://graph.qq.com/oauth2.0/token \
  -e QQ_OPEN_ID_ENDPOINT=https://graph.qq.com/oauth2.0/me \
  -e ENABLE_DEV_SMS=false \
  -e SMS_PROVIDER= \
  -e ENABLE_WECHAT_LOGIN=true \
  -e WECHAT_APP_ID=internal-rpc-config-smoke-app \
  -e WECHAT_APP_SECRET=internal-rpc-config-smoke-secret

# Test-only OAuth endpoint overrides must never be accepted by a production
# AuthService, even when the provider and SMS settings otherwise look valid.
run_rejection_case \
  oauth-endpoint-override-rejected-in-production \
  'OAuth endpoint overrides are only allowed in local, development, or testing environments' \
  -e APP_ENV=production \
  -e ENABLE_DEV_LOGIN=false \
  -e SOCIAL_OAUTH_ALLOW_INSECURE_HTTP=false \
  -e ENABLE_DEV_SMS=false \
  -e SMS_PROVIDER=twilio \
  -e ENABLE_WECHAT_LOGIN=true \
  -e WECHAT_APP_ID=internal-rpc-config-smoke-app \
  -e WECHAT_APP_SECRET=internal-rpc-config-smoke-secret \
  -e WECHAT_TOKEN_ENDPOINT=http://fake-oauth:8081/sns/oauth2/access_token

run_rejection_case \
  insecure-oauth-http-rejected-in-production \
  'SOCIAL_OAUTH_ALLOW_INSECURE_HTTP is only allowed in local, development, or testing environments' \
  -e APP_ENV=production \
  -e ENABLE_DEV_LOGIN=false \
  -e ENABLE_DEV_SMS=false \
  -e SOCIAL_OAUTH_ALLOW_INSECURE_HTTP=true \
  -e WECHAT_TOKEN_ENDPOINT=https://api.weixin.qq.com/sns/oauth2/access_token \
  -e QQ_TOKEN_ENDPOINT=https://graph.qq.com/oauth2.0/token \
  -e QQ_OPEN_ID_ENDPOINT=https://graph.qq.com/oauth2.0/me
