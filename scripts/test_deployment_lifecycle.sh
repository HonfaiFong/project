#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

credentials=(
  PG_AUTH_RUNTIME_PASSWORD PG_USER_RUNTIME_PASSWORD PG_CONTENT_RUNTIME_PASSWORD
  PG_SOCIAL_RUNTIME_PASSWORD PG_CHAT_RUNTIME_PASSWORD PG_USER_OUTBOX_RELAY_PASSWORD
  PG_MIGRATION_PASSWORD PG_MESSAGE_RUNTIME_PASSWORD PG_MESSAGE_OUTBOX_RELAY_PASSWORD
)
for name in "${credentials[@]}"; do
  export "${name}=deployment-test-password"
done
export POSTGRES_USER=postgres POSTGRES_DB=custom_database

# Match the official entrypoint's sourcing path for non-executable init files.
bash -ec '
  psql() { cat >/dev/null; }
  unset role_name role_password
  before=$-
  source infra/docker/postgresql/init/01-create-roles.sh
  source infra/docker/postgresql/init/02-message-storage.sh
  [[ "$-" == "$before" ]] || { echo "Init changed entrypoint shell options" >&2; exit 1; }
  [[ -z "${role_name+x}${role_password+x}" ]] || { echo "Init leaked account variables" >&2; exit 1; }
  ! declare -F create_role_password >/dev/null || { echo "Init leaked functions" >&2; exit 1; }
'

if bash -ec '
  psql() { cat >/dev/null; return 42; }
  source infra/docker/postgresql/init/01-create-roles.sh
'; then
  echo 'Init hid a PostgreSQL failure' >&2
  exit 1
fi

if env -u PG_AUTH_RUNTIME_PASSWORD bash -ec '
  psql() { cat >/dev/null; }
  source infra/docker/postgresql/init/01-create-roles.sh
'; then
  echo 'Init accepted a missing runtime password' >&2
  exit 1
fi

scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT
cat > "$scratch/docker" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$DEPLOYMENT_TEST_LOG"
case "$*" in
  'compose exec -T postgres bash /docker-entrypoint-initdb.d/02-message-storage.sh') exit "${DEPLOYMENT_TEST_MESSAGE_INIT_EXIT:-0}" ;;
  'compose run --rm --no-deps database-migrate migrate') exit "${DEPLOYMENT_TEST_MIGRATION_EXIT:-0}" ;;
  *) echo "Unexpected deployment command: $*" >&2; exit 1 ;;
esac
STUB
chmod +x "$scratch/docker"
export PATH="$scratch:$PATH"
export DEPLOYMENT_TEST_LOG="$scratch/commands"

make migrate
[[ "$(wc -l < "$DEPLOYMENT_TEST_LOG" | tr -d ' ')" == 2 ]]
if DEPLOYMENT_TEST_MIGRATION_EXIT=42 make migrate; then
  echo 'Migration target hid a migration failure' >&2
  exit 1
fi


if DEPLOYMENT_TEST_MESSAGE_INIT_EXIT=42 make migrate; then
  echo 'Migration target hid a message account initialization failure' >&2
  exit 1
fi

echo 'Deployment lifecycle regression checks passed.'
