#!/usr/bin/env bash
# Phinx holds a PostgreSQL advisory lock on its PDO session.
# New relational migrations belong in database/postgresql/migrations/.
# 可传入“status”或“create CreateExampleTable”等参数。
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
if [ "$#" -eq 0 ]; then
  set -- migrate
fi
docker compose run --rm --no-deps database-migrate "$@"
