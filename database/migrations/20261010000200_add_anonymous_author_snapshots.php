<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * Stores the display identity selected at write time. A zero mode means that
 * an old row has no trustworthy snapshot and must be projected anonymously.
 */
final class AddAnonymousAuthorSnapshots extends AbstractMigration
{
    /** @var array<string, array<string, array<string, string>>> */
    private const TABLES = [
        'content_db' => [
            'posts' => [
                'identity_mode' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
                'author_display_name' => 'VARCHAR(64) NULL',
                'author_avatar_key' => 'VARCHAR(255) NULL',
                'author_anonymous_ref' => 'VARCHAR(128) NULL',
                'author_scope_type' => 'VARCHAR(255) NULL',
                'author_scope_id' => 'VARCHAR(255) NULL',
            ],
            'comments' => [
                'identity_mode' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
                'author_display_name' => 'VARCHAR(64) NULL',
                'author_avatar_key' => 'VARCHAR(255) NULL',
                'author_anonymous_ref' => 'VARCHAR(128) NULL',
                'author_scope_type' => 'VARCHAR(255) NULL',
                'author_scope_id' => 'VARCHAR(255) NULL',
            ],
        ],
        'chat_db' => [
            'conversation_members' => [
                'identity_mode' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
                'display_name_snapshot' => 'VARCHAR(64) NULL',
                'avatar_key_snapshot' => 'VARCHAR(255) NULL',
                'anonymous_ref' => 'VARCHAR(128) NULL',
                'scope_type' => 'VARCHAR(255) NULL',
                'scope_id' => 'VARCHAR(255) NULL',
            ],
        ],
        'message_db' => [
            'messages' => [
                'sender_identity_mode' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
                'sender_display_name' => 'VARCHAR(64) NULL',
                'sender_avatar_key' => 'VARCHAR(255) NULL',
                'sender_anonymous_ref' => 'VARCHAR(128) NULL',
                'sender_scope_type' => 'VARCHAR(255) NULL',
                'sender_scope_id' => 'VARCHAR(255) NULL',
            ],
        ],
    ];

    public function up(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        $prefix = $this->schemaPrefix();

        /** @var list<array{schema:string, table:string, clauses:list<string>}> $plans */
        $plans = [];
        foreach (self::TABLES as $database => $tables) {
            $schema = $prefix . $database;
            foreach ($tables as $table => $columns) {
                if (!$this->tableExists($pdo, $schema, $table)) {
                    throw new RuntimeException(
                        $schema . '.' . $table . ' does not exist; run the legacy schema import first.',
                    );
                }

                $existing = $this->columns($pdo, $schema, $table);
                $clauses = [];
                foreach ($columns as $name => $definition) {
                    if (!isset($existing[$name])) {
                        $clauses[] = 'ADD COLUMN `' . $name . '` ' . $definition;
                    } elseif (!$this->isExpectedSnapshotColumn($name, $existing[$name])) {
                        throw new RuntimeException(
                            $schema . '.' . $table . '.' . $name
                            . ' exists but has an incompatible type, nullability, or default.',
                        );
                    }
                }
                $plans[] = ['schema' => $schema, 'table' => $table, 'clauses' => $clauses];
            }
        }

        // Complete all metadata validation before issuing DDL. MySQL DDL is
        // auto-committing, so a later schema mismatch must not leave an
        // earlier table half-migrated by this run.
        foreach ($plans as $plan) {
            if ($plan['clauses'] === []) {
                continue;
            }

            $this->execute(
                'ALTER TABLE ' . $this->identifier($plan['schema']) . '.`' . $plan['table'] . '` '
                . implode(', ', $plan['clauses']),
            );
        }
    }

    /** @throws IrreversibleMigrationException */
    public function down(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        $prefix = $this->schemaPrefix();
        /** @var list<array{schema:string, table:string, clauses:list<string>}> $plans */
        $plans = [];
        foreach (self::TABLES as $database => $tables) {
            $schema = $prefix . $database;
            foreach ($tables as $table => $columns) {
                if (!$this->tableExists($pdo, $schema, $table)) {
                    continue;
                }
                $existing = $this->columns($pdo, $schema, $table);
                $clauses = [];
                foreach (array_keys($columns) as $name) {
                    if (isset($existing[$name])) {
                        $clauses[] = 'DROP COLUMN `' . $name . '`';
                    }
                }
                if ($clauses === []) {
                    continue;
                }

                if ($this->tableHasRows($pdo, $schema, $table)) {
                    throw new IrreversibleMigrationException(
                        'Cannot remove anonymous identity snapshots from ' . $schema . '.' . $table
                        . ' while rows exist; rollback would destroy historical identity semantics.',
                    );
                }

                $plans[] = ['schema' => $schema, 'table' => $table, 'clauses' => $clauses];
            }
        }
        foreach ($plans as $plan) {
            $this->execute(
                'ALTER TABLE ' . $this->identifier($plan['schema']) . '.`' . $plan['table'] . '` '
                . implode(', ', $plan['clauses']),
            );
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function columns(PDO $pdo, string $schema, string $table): array
    {
        $statement = $pdo->prepare(
            'SELECT `COLUMN_NAME` AS column_name, `DATA_TYPE` AS data_type, '
            . '`COLUMN_TYPE` AS column_type, `IS_NULLABLE` AS is_nullable, '
            . '`COLUMN_DEFAULT` AS column_default '
            . 'FROM `information_schema`.`columns` '
            . 'WHERE `TABLE_SCHEMA` = :schema AND `TABLE_NAME` = :table',
        );
        $statement->execute(['schema' => $schema, 'table' => $table]);
        $columns = [];
        while ($column = $statement->fetch(PDO::FETCH_ASSOC)) {
            $columns[(string)$column['column_name']] = $column;
        }

        return $columns;
    }

    /** @param array<string, mixed> $column */
    private function isExpectedSnapshotColumn(string $name, array $column): bool
    {
        $modeColumn = in_array($name, ['identity_mode', 'sender_identity_mode'], true);
        if ($modeColumn) {
            return strtolower((string)$column['data_type']) === 'tinyint'
                && strtolower((string)$column['column_type']) === 'tinyint unsigned'
                && strtoupper((string)$column['is_nullable']) === 'NO'
                && (string)$column['column_default'] === '0';
        }

        $length = match (true) {
            in_array($name, ['author_display_name', 'display_name_snapshot', 'sender_display_name'], true) => 64,
            in_array($name, ['author_avatar_key', 'avatar_key_snapshot', 'sender_avatar_key'], true) => 255,
            in_array($name, ['author_anonymous_ref', 'anonymous_ref', 'sender_anonymous_ref'], true) => 128,
            in_array(
                $name,
                ['author_scope_type', 'author_scope_id', 'scope_type', 'scope_id', 'sender_scope_type', 'sender_scope_id'],
                true,
            ) => 255,
            default => null,
        };
        if ($length === null) {
            return false;
        }

        return strtolower((string)$column['data_type']) === 'varchar'
            && strtolower((string)$column['column_type']) === 'varchar(' . $length . ')'
            && strtoupper((string)$column['is_nullable']) === 'YES'
            && $column['column_default'] === null;
    }

    private function tableHasRows(PDO $pdo, string $schema, string $table): bool
    {
        $statement = $pdo->query(
            'SELECT 1 FROM ' . $this->identifier($schema) . '.`' . $table . '` LIMIT 1',
        );

        return $statement->fetchColumn() !== false;
    }

    private function tableExists(PDO $pdo, string $schema, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM `information_schema`.`tables` '
            . 'WHERE `TABLE_SCHEMA` = :schema AND `TABLE_NAME` = :table LIMIT 1',
        );
        $statement->execute(['schema' => $schema, 'table' => $table]);

        return $statement->fetchColumn() !== false;
    }

    private function schemaPrefix(): string
    {
        $prefix = $this->getAdapter()->getOption('schema_prefix') ?? '';
        if (!is_string($prefix) || preg_match('/\A[a-zA-Z0-9_]*\z/', $prefix) !== 1) {
            throw new RuntimeException('schema_prefix must contain only ASCII letters, digits, and underscores.');
        }

        return $prefix;
    }

    private function identifier(string $identifier): string
    {
        return '`' . $identifier . '`';
    }
}
