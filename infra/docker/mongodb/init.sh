#!/usr/bin/env bash
set -euo pipefail
for attempt in $(seq 1 60); do
  if mongosh --quiet --host mongo:27017 \
    -u "$MONGODB_ADMIN_USER" -p "$MONGODB_ADMIN_PASSWORD" \
    --authenticationDatabase admin --eval 'db.adminCommand({ping:1})' >/dev/null 2>&1; then
    exec mongosh --quiet --host mongo:27017 \
      -u "$MONGODB_ADMIN_USER" -p "$MONGODB_ADMIN_PASSWORD" \
      --authenticationDatabase admin /init.js
  fi
  sleep 2
done
echo 'MongoDB admin connection did not become ready' >&2
exit 1
