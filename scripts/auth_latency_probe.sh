#!/usr/bin/env bash
# 社交登录路径的真实调用链延迟探测。
#
# 针对运行中的服务栈，通过网关 HTTP API 发起 N 次社交登录（网关 -> auth-service gRPC -> 模拟 OAuth HTTP +
# MySQL/Redis -> user-service gRPC），报告完整请求延迟的 P50/P95/P99，包含
# tests/Unit/GrpcDeadlineBudgetBenchmarkTest 无法测量的 auth->user gRPC 调用。本探测补充了仅针对真实 MySQL/Redis
# 服务测量耗时的 tests/Integration/AuthWarmPathLatencyTest.php。
#
# 每次尝试都使用新的一次性授权码（随机数不同，身份相同），与端到端测试约定一致。REQUESTS 须低于部署的
# SOCIAL_LOGIN_RATE_SCENARIO_LIMIT / DEVICE 限额（同一 IP 默认每 60 秒 30 / 20 次）；同一分钟内运行
# scripts/social_e2e_test.sh 已消耗其中 15 次。
set -euo pipefail

BASE_URL="${API_BASE_URL:-http://127.0.0.1:8787}"
REQUESTS="${AUTH_LATENCY_REQUESTS:-10}"
BUDGET_MS="${AUTH_LATENCY_BUDGET_MS:-5000}"
BODY_FILE="$(mktemp)"
TIMINGS_FILE="$(mktemp)"
trap 'rm -f "$BODY_FILE" "$TIMINGS_FILE"' EXIT

identity="probe-latency-$(date +%s)-$RANDOM"

for i in $(seq 1 "$REQUESTS"); do
  # 不同随机数对应同一外部身份的不同一次性授权码。
  code="wechat-primary-${identity}~run${i}"
  status="$(curl --silent --show-error --max-time 10 -o "$BODY_FILE" -w '%{http_code} %{time_total}' \
    -X POST "${BASE_URL}/api/v1/auth/social/login" \
    -H 'Content-Type: application/json' \
    --data "{\"provider\":\"wechat\",\"code\":\"${code}\",\"device\":{\"device_uuid\":\"latency-probe-${identity}\",\"platform\":\"ios\"}}")"
  http_code="${status%% *}"
  time_total="${status##* }"
  # 探测身份首次登录会注册用户（201），后续使用同一身份登录则恢复会话（200）；业务代码 0 是判定成功的权威依据。
  if [[ "$http_code" != "201" && "$http_code" != "200" ]]; then
    echo "FAILED: probe login $i returned HTTP ${http_code}" >&2
    exit 1
  fi
  # 不输出原始响应体：仅报告数值型业务代码（整数或数字字符串），其他载荷统一显示固定文本“invalid”。
  business_code="$(php -r '$b=json_decode(file_get_contents($argv[1]), true); $c=is_array($b) ? ($b["code"] ?? null) : null; echo (is_int($c) || (is_string($c) && preg_match("/^-?\\d+$/", $c) === 1)) ? (string) $c : "invalid";' "$BODY_FILE")"
  if [[ "$business_code" != "0" ]]; then
    echo "FAILED: probe login $i returned business code ${business_code}" >&2
    exit 1
  fi
  printf '%s\n' "$time_total" >> "$TIMINGS_FILE"
done

# 同一次排序同时用于输出报告和预算检查，确保报告的 p95 与执行限制使用的 p95 一致。
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
