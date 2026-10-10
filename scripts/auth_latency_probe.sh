#!/usr/bin/env bash
# Real-chain latency probe for the social login path.
#
# Drives N social logins through the gateway HTTP API against a running stack
# (gateway -> auth-service gRPC -> fake OAuth HTTP + MySQL/Redis -> user-service
# gRPC) and reports P50/P95/P99 of the full request latency, including the
# auth->user gRPC hop that tests/Unit/GrpcDeadlineBudgetBenchmarkTest cannot
# measure. Complements tests/Integration/AuthWarmPathLatencyTest.php, which
# measures the MySQL/Redis share against real services.
#
# Every attempt uses a fresh single-use authorization code (new nonce, same
# identity), mirroring the E2E contract. Keep REQUESTS below the deployed
# SOCIAL_LOGIN_RATE_SCENARIO_LIMIT / DEVICE limits (defaults 30 / 20 per 60 s
# from one IP), and remember scripts/social_e2e_test.sh already spends 15 of
# that budget when run in the same minute.
set -euo pipefail

BASE_URL="${API_BASE_URL:-http://127.0.0.1:8787}"
REQUESTS="${AUTH_LATENCY_REQUESTS:-10}"
BUDGET_MS="${AUTH_LATENCY_BUDGET_MS:-5000}"
BODY_FILE="$(mktemp)"
TIMINGS_FILE="$(mktemp)"
trap 'rm -f "$BODY_FILE" "$TIMINGS_FILE"' EXIT

identity="probe-latency-$(date +%s)-$RANDOM"

for i in $(seq 1 "$REQUESTS"); do
  # Distinct nonce => distinct single-use code for the same external identity.
  code="wechat-primary-${identity}~run${i}"
  status="$(curl --silent --show-error --max-time 10 -o "$BODY_FILE" -w '%{http_code} %{time_total}' \
    -X POST "${BASE_URL}/api/v1/auth/social/login" \
    -H 'Content-Type: application/json' \
    --data "{\"provider\":\"wechat\",\"code\":\"${code}\",\"device\":{\"device_uuid\":\"latency-probe-${identity}\",\"platform\":\"ios\"}}")"
  http_code="${status%% *}"
  time_total="${status##* }"
  # First login for a probe identity registers the user (201); every further
  # login with the same identity resumes the session (200). Business code 0 is
  # the authoritative success check.
  if [[ "$http_code" != "201" && "$http_code" != "200" ]]; then
    echo "FAILED: probe login $i returned HTTP ${http_code}" >&2
    exit 1
  fi
  # Never echo the raw body: report only a numeric business code (int or digit
  # string); any other payload collapses to the fixed word "invalid".
  business_code="$(php -r '$b=json_decode(file_get_contents($argv[1]), true); $c=is_array($b) ? ($b["code"] ?? null) : null; echo (is_int($c) || (is_string($c) && preg_match("/^-?\\d+$/", $c) === 1)) ? (string) $c : "invalid";' "$BODY_FILE")"
  if [[ "$business_code" != "0" ]]; then
    echo "FAILED: probe login $i returned business code ${business_code}" >&2
    exit 1
  fi
  printf '%s\n' "$time_total" >> "$TIMINGS_FILE"
done

# One sorted pass feeds both the printed report and the budget gate: the
# reported p95 and the enforced p95 can never diverge.
percentiles="$(sort -g "$TIMINGS_FILE" | awk -v n="$REQUESTS" '
  NR == int((n * 50 + 99) / 100) { p50 = $1 }
  NR == int((n * 95 + 99) / 100) { p95 = $1 }
  NR == int((n * 99 + 99) / 100) { p99 = $1 }
  END { printf "%.1f %.1f %.1f\n", p50 * 1000, p95 * 1000, p99 * 1000 }')"
read -r p50_ms p95_ms p99_ms <<<"$percentiles"
printf 'social login full-chain latency (%s requests):\n  p50 = %s ms\n  p95 = %s ms\n  p99 = %s ms\n' \
  "$REQUESTS" "$p50_ms" "$p95_ms" "$p99_ms"

if awk -v p="$p95_ms" -v b="$BUDGET_MS" 'BEGIN { exit !(p >= b) }'; then
  echo "FAILED: p95 ${p95_ms} ms exceeds the ${BUDGET_MS} ms budget (set AUTH_LATENCY_BUDGET_MS to adjust)" >&2
  exit 1
fi
