#!/usr/bin/env bash
(
set -euo pipefail

: "${POSTGRES_DB:=social_backend}"
: "${PG_MIGRATION_PASSWORD:?PG_MIGRATION_PASSWORD is required}"
: "${PG_AUTH_RUNTIME_PASSWORD:?PG_AUTH_RUNTIME_PASSWORD is required}"
: "${PG_USER_RUNTIME_PASSWORD:?PG_USER_RUNTIME_PASSWORD is required}"
: "${PG_CONTENT_RUNTIME_PASSWORD:?PG_CONTENT_RUNTIME_PASSWORD is required}"
: "${PG_SOCIAL_RUNTIME_PASSWORD:?PG_SOCIAL_RUNTIME_PASSWORD is required}"
: "${PG_CHAT_RUNTIME_PASSWORD:?PG_CHAT_RUNTIME_PASSWORD is required}"
: "${PG_USER_OUTBOX_RELAY_PASSWORD:?PG_USER_OUTBOX_RELAY_PASSWORD is required}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<'SQL'
CREATE ROLE migration_ddl LOGIN;
CREATE ROLE auth_runtime LOGIN;
CREATE ROLE user_runtime LOGIN;
CREATE ROLE content_runtime LOGIN;
CREATE ROLE social_runtime LOGIN;
CREATE ROLE chat_runtime LOGIN;
CREATE ROLE user_outbox_relay LOGIN;
SQL

create_role_password() {
  local role_name="$1"
  local role_password="$2"
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    --set=role_name="$role_name" --set=role_password="$role_password" <<'SQL'
SELECT format('ALTER ROLE %I PASSWORD %L', :'role_name', :'role_password') \gexec
SQL
}

create_role_password migration_ddl "$PG_MIGRATION_PASSWORD"
create_role_password auth_runtime "$PG_AUTH_RUNTIME_PASSWORD"
create_role_password user_runtime "$PG_USER_RUNTIME_PASSWORD"
create_role_password content_runtime "$PG_CONTENT_RUNTIME_PASSWORD"
create_role_password social_runtime "$PG_SOCIAL_RUNTIME_PASSWORD"
create_role_password chat_runtime "$PG_CHAT_RUNTIME_PASSWORD"
create_role_password user_outbox_relay "$PG_USER_OUTBOX_RELAY_PASSWORD"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --set=database_name="$POSTGRES_DB" <<'SQL'
REVOKE CONNECT ON DATABASE :"database_name" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"database_name" TO migration_ddl, auth_runtime, user_runtime, content_runtime, social_runtime, chat_runtime, user_outbox_relay;
CREATE SCHEMA auth_db AUTHORIZATION migration_ddl;
CREATE SCHEMA user_db AUTHORIZATION migration_ddl;
CREATE SCHEMA content_db AUTHORIZATION migration_ddl;
CREATE SCHEMA social_db AUTHORIZATION migration_ddl;
CREATE SCHEMA chat_db AUTHORIZATION migration_ddl;
CREATE SCHEMA meta AUTHORIZATION migration_ddl;
GRANT USAGE ON SCHEMA auth_db TO auth_runtime;
GRANT USAGE ON SCHEMA user_db TO user_runtime, user_outbox_relay;
GRANT USAGE ON SCHEMA content_db TO content_runtime;
GRANT USAGE ON SCHEMA social_db TO social_runtime;
GRANT USAGE ON SCHEMA chat_db TO chat_runtime;
ALTER DEFAULT PRIVILEGES FOR ROLE migration_ddl IN SCHEMA auth_db GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO auth_runtime;
ALTER DEFAULT PRIVILEGES FOR ROLE migration_ddl IN SCHEMA user_db GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO user_runtime;
ALTER DEFAULT PRIVILEGES FOR ROLE migration_ddl IN SCHEMA content_db GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO content_runtime;
ALTER DEFAULT PRIVILEGES FOR ROLE migration_ddl IN SCHEMA social_db GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO social_runtime;
ALTER DEFAULT PRIVILEGES FOR ROLE migration_ddl IN SCHEMA chat_db GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO chat_runtime;
SQL
)
