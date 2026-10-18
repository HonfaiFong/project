#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../../scripts/wait_for_api.sh"
test_dir="$(mktemp -d)"
trap 'rm -f "$test_dir/count" "$test_dir/body" "$test_dir/log"; rmdir "$test_dir"' EXIT
counter_file="$test_dir/count"
mode=transient

# Replace transport and sleep; exercise the actual JSON validation with PHP.
curl() {
  local count output
  count="$(cat "$counter_file")"
  count=$((count + 1))
  echo "$count" > "$counter_file"
  while (($#)); do
    if [[ "$1" == -o ]]; then output="$2"; break; fi
    shift
  done
  if [[ "$mode" == transient && "$count" -gt 1 ]]; then
    echo '{"code":0,"data":{"status":"SERVING"}}' > "$output"
    printf 200
  elif [[ "$mode" == invalid ]]; then
    echo '{"code":0,"data":{"status":"NOT_SERVING"}}' > "$output"
    printf 200
  elif [[ "$mode" == disconnected ]]; then
    return 7
  else
    echo '{"code":1003001,"details":{"failures":{"auth_service":"probe failure"}}}' > "$output"
    printf 503
  fi
}
sleep() { :; }

echo 0 > "$counter_file"
wait_for_api http://unused "$test_dir/body" 2> "$test_dir/log"
[[ "$(cat "$counter_file")" == 2 ]]
grep -q 'probe failure' "$test_dir/log"
for mode in persistent invalid disconnected; do
  echo 0 > "$counter_file"
  if wait_for_api http://unused "$test_dir/body" 2> "$test_dir/log"; then
    echo "FAILED: ${mode} readiness must fail" >&2
    exit 1
  fi
  [[ "$(cat "$counter_file")" == 10 ]]
  grep -q 'API did not become ready' "$test_dir/log"
done
echo 'Readiness shell tests passed'
