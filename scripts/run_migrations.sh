#!/usr/bin/env bash
# Applies pending SQL migrations from infra/docker/mysql/migrations/ against
# the database exposed by the `mysql` compose service. Applied files are
# tracked in meta.schema_migrations so each migration runs exactly once,
# regardless of whether the volume was initialized before migrations existed.
set -euo pipefail

MIGRATION_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../infra/docker/mysql/migrations" && pwd)"

run_sql() {
  docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$@"' mysql "$@"
}

run_sql -e "CREATE DATABASE IF NOT EXISTS meta CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
run_sql -e "CREATE TABLE IF NOT EXISTS meta.schema_migrations (name VARCHAR(255) NOT NULL PRIMARY KEY, applied_at DATETIME(3) NOT NULL) ENGINE=InnoDB"

applied="$(run_sql -N -e "SELECT name FROM meta.schema_migrations ORDER BY name")"

pending=0
for file in "$MIGRATION_DIR"/*.sql; do
  [ -e "$file" ] || { echo "No migrations found in $MIGRATION_DIR"; exit 0; }

  name="$(basename "$file")"
  if printf '%s\n' "$applied" | grep -Fxq "$name"; then
    continue
  fi

  echo "Applying migration: $name"
  run_sql < "$file"
  pending=$((pending + 1))
done

if [ "$pending" -eq 0 ]; then
  echo "Migrations up to date."
else
  echo "Applied $pending migration(s)."
fi
