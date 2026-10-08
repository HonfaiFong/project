#!/usr/bin/env bash
# Phinx holds a MySQL advisory lock on its own PDO session. Legacy SQL is
# adopted by the first migration; new migrations belong in database/migrations/.
# Pass e.g. "status" or "create CreateExampleTable" here.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
if [ "$#" -eq 0 ]; then
  set -- migrate
fi
docker compose run --rm --no-deps database-migrate "$@"
