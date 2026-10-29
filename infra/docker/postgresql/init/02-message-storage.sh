#!/usr/bin/env bash
# Also safe to run explicitly as the administrator when upgrading an existing volume.
(
set -euo pipefail
: "${POSTGRES_USER:=postgres}"
: "${POSTGRES_DB:=social_backend}"
: "${PG_MESSAGE_RUNTIME_PASSWORD:?PG_MESSAGE_RUNTIME_PASSWORD is required}"
: "${PG_MESSAGE_OUTBOX_RELAY_PASSWORD:?PG_MESSAGE_OUTBOX_RELAY_PASSWORD is required}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
  --set=runtime_password="$PG_MESSAGE_RUNTIME_PASSWORD" \
  --set=relay_password="$PG_MESSAGE_OUTBOX_RELAY_PASSWORD" \
  --set=database_name="$POSTGRES_DB" <<'SQL'
BEGIN;
SELECT pg_advisory_xact_lock(20261029000000);
SELECT 'CREATE ROLE message_runtime LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'message_runtime') \gexec
SELECT 'CREATE ROLE message_outbox_relay LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'message_outbox_relay') \gexec
SELECT format('ALTER ROLE message_runtime PASSWORD %L', :'runtime_password') \gexec
SELECT format('ALTER ROLE message_outbox_relay PASSWORD %L', :'relay_password') \gexec
GRANT CONNECT ON DATABASE :"database_name" TO message_runtime, message_outbox_relay;
CREATE SCHEMA IF NOT EXISTS message_db AUTHORIZATION migration_ddl;
GRANT USAGE ON SCHEMA message_db TO message_runtime, message_outbox_relay;
COMMIT;
SQL
)
