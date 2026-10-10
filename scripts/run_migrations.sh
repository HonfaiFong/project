#!/usr/bin/env bash
# Phinx 在自身 PDO 会话上持有 MySQL 咨询锁。首次迁移纳入历史 SQL；新迁移应放在 database/migrations/。
# 可传入“status”或“create CreateExampleTable”等参数。
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
if [ "$#" -eq 0 ]; then
  set -- migrate
fi
docker compose run --rm --no-deps database-migrate "$@"
