#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

credentials=(
  MYSQL_ROOT_PASSWORD MYSQL_AUTH_RUNTIME_PASSWORD MYSQL_USER_RUNTIME_PASSWORD
  MYSQL_CONTENT_RUNTIME_PASSWORD MYSQL_SOCIAL_RUNTIME_PASSWORD
  MYSQL_CHAT_RUNTIME_PASSWORD MYSQL_MESSAGE_RUNTIME_PASSWORD
  MYSQL_USER_OUTBOX_RELAY_PASSWORD MYSQL_MESSAGE_OUTBOX_RELAY_PASSWORD
  MYSQL_MIGRATION_PASSWORD
)
for name in "${credentials[@]}"; do
  export "${name}=deployment-test-password"
done

# Match the official entrypoint's sourcing path for non-executable init files.
bash -ec '
  mysql() { cat >/dev/null; }
  unset MYSQL_ONETIME_PASSWORD user database password
  before=$-
  source infra/docker/mysql/init/02-runtime-accounts.sh
  [[ "$-" == "$before" ]] || { echo "Init changed entrypoint shell options" >&2; exit 1; }
  [[ -z "${user+x}${database+x}${password+x}" ]] || { echo "Init leaked account variables" >&2; exit 1; }
  ! declare -F mysql_root >/dev/null || { echo "Init leaked functions" >&2; exit 1; }
  [[ -z "$MYSQL_ONETIME_PASSWORD" ]]
'

if bash -ec '
  mysql() { cat >/dev/null; return 42; }
  source infra/docker/mysql/init/02-runtime-accounts.sh
'; then
  echo 'Init hid a MySQL failure' >&2
  exit 1
fi

if env -u MYSQL_AUTH_RUNTIME_PASSWORD bash -ec '
  mysql() { cat >/dev/null; }
  source infra/docker/mysql/init/02-runtime-accounts.sh
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
  'compose run --rm --no-deps database-migrate migrate') exit "${DEPLOYMENT_TEST_MIGRATION_EXIT:-0}" ;;
  *) echo "Unexpected deployment command: $*" >&2; exit 1 ;;
esac
STUB
chmod +x "$scratch/docker"
export PATH="$scratch:$PATH"
export DEPLOYMENT_TEST_LOG="$scratch/commands"

make migrate
[[ "$(wc -l < "$DEPLOYMENT_TEST_LOG" | tr -d ' ')" == 1 ]]
if DEPLOYMENT_TEST_MIGRATION_EXIT=42 make migrate; then
  echo 'Migration target hid a migration failure' >&2
  exit 1
fi

echo 'Deployment lifecycle regression checks passed.'
