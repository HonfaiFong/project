#!/usr/bin/env bash

# Readiness is retried only before a suite; API assertions remain single-shot.
wait_for_api() {
  local base_url="$1" body_file="$2" status attempt
  for ((attempt = 1; attempt <= 10; attempt++)); do
    : > "$body_file"
    status="$(curl --silent --show-error --max-time 3 -o "$body_file" -w '%{http_code}' "${base_url}/health")" || status=000
    if [[ "$status" == 200 ]] && php -r '
      $body=json_decode(file_get_contents($argv[1]), true);
      exit(($body["code"] ?? null) === 0 && ($body["data"]["status"] ?? null) === "SERVING" ? 0 : 1);
    ' "$body_file"; then
      return 0
    fi
    echo "Readiness attempt ${attempt}/10: HTTP ${status}" >&2
    [[ ! -s "$body_file" ]] || cat "$body_file" >&2
    if ((attempt < 10)); then sleep 1; fi
  done
  echo 'API did not become ready' >&2
  return 1
}
