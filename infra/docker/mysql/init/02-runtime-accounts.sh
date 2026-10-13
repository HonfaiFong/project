#!/bin/sh

# The MySQL image executes this file after 01-databases.sql on a new volume.
# It is intentionally idempotent so operators can run it again after rotating
# credentials or when upgrading an existing volume.
(
set -eu

: "${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD is required}"
: "${MYSQL_AUTH_RUNTIME_PASSWORD:?MYSQL_AUTH_RUNTIME_PASSWORD is required}"
: "${MYSQL_USER_RUNTIME_PASSWORD:?MYSQL_USER_RUNTIME_PASSWORD is required}"
: "${MYSQL_CONTENT_RUNTIME_PASSWORD:?MYSQL_CONTENT_RUNTIME_PASSWORD is required}"
: "${MYSQL_SOCIAL_RUNTIME_PASSWORD:?MYSQL_SOCIAL_RUNTIME_PASSWORD is required}"
: "${MYSQL_CHAT_RUNTIME_PASSWORD:?MYSQL_CHAT_RUNTIME_PASSWORD is required}"
: "${MYSQL_MESSAGE_RUNTIME_PASSWORD:?MYSQL_MESSAGE_RUNTIME_PASSWORD is required}"
: "${MYSQL_USER_OUTBOX_RELAY_PASSWORD:?MYSQL_USER_OUTBOX_RELAY_PASSWORD is required}"
: "${MYSQL_MESSAGE_OUTBOX_RELAY_PASSWORD:?MYSQL_MESSAGE_OUTBOX_RELAY_PASSWORD is required}"
: "${MYSQL_MIGRATION_PASSWORD:?MYSQL_MIGRATION_PASSWORD is required}"

# MySQL string literals use backslash escaping by default. Escape both
# backslashes and quotes before putting an env-injected secret in SQL. The
# compose templates recommend hex secrets, but this also supports punctuation.
escape_mysql_literal() {
    printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e "s/'/''/g"
}

mysql_root() {
    mysql --protocol=socket --user=root --password="${MYSQL_ROOT_PASSWORD}" --batch --skip-column-names
}

create_runtime_user() {
    user="$1"
    database="$2"
    password=$(escape_mysql_literal "$3")

    mysql_root <<SQL
CREATE USER IF NOT EXISTS '${user}'@'%';
ALTER USER '${user}'@'%' IDENTIFIED BY '${password}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${user}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${database}\`.* TO '${user}'@'%';
SQL
}

create_relay_user() {
    user="$1"
    database="$2"
    password=$(escape_mysql_literal "$3")

    mysql_root <<SQL
CREATE USER IF NOT EXISTS '${user}'@'%';
ALTER USER '${user}'@'%' IDENTIFIED BY '${password}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${user}'@'%';
GRANT SELECT, UPDATE ON \`${database}\`.\`outbox_events\` TO '${user}'@'%';
SQL
}

create_migration_user() {
    password=$(escape_mysql_literal "$1")

    # Phinx owns schema DDL and the migration metadata database. It receives
    # explicit DDL/DML privileges on logical schemas whose names end in _db or
    # meta. This covers both the production schemas and MYSQL_SCHEMA_PREFIX
    # databases while keeping mysql/system schemas outside the grant. It has no
    # GRANT OPTION and no CREATE USER privilege, so it cannot delegate or
    # become a runtime account.
    mysql_root <<SQL
CREATE USER IF NOT EXISTS 'migration_ddl'@'%';
ALTER USER 'migration_ddl'@'%' IDENTIFIED BY '${password}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'migration_ddl'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES,
      CREATE VIEW, SHOW VIEW, TRIGGER, EVENT, CREATE TEMPORARY TABLES, LOCK TABLES
      ON \`%_db\`.* TO 'migration_ddl'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES,
      CREATE VIEW, SHOW VIEW, TRIGGER, EVENT, CREATE TEMPORARY TABLES, LOCK TABLES
      ON \`%meta\`.* TO 'migration_ddl'@'%';
SQL
}

create_runtime_user auth_runtime auth_db "${MYSQL_AUTH_RUNTIME_PASSWORD}"
create_runtime_user user_runtime user_db "${MYSQL_USER_RUNTIME_PASSWORD}"
create_runtime_user content_runtime content_db "${MYSQL_CONTENT_RUNTIME_PASSWORD}"
create_runtime_user social_runtime social_db "${MYSQL_SOCIAL_RUNTIME_PASSWORD}"
create_runtime_user chat_runtime chat_db "${MYSQL_CHAT_RUNTIME_PASSWORD}"
create_runtime_user message_runtime message_db "${MYSQL_MESSAGE_RUNTIME_PASSWORD}"
create_relay_user user_outbox_relay user_db "${MYSQL_USER_OUTBOX_RELAY_PASSWORD}"
create_relay_user message_outbox_relay message_db "${MYSQL_MESSAGE_OUTBOX_RELAY_PASSWORD}"
create_migration_user "${MYSQL_MIGRATION_PASSWORD}"

echo "MySQL runtime, outbox relay, and migration accounts are present and reconciled."
)
