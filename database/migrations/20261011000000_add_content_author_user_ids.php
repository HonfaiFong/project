<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/** Keep the account owner private while allowing content to re-authorize rows. */
final class AddContentAuthorUserIds extends AbstractMigration
{
    public function up(): void
    {
        foreach (['posts', 'comments'] as $table) {
            $fullTable = 'content_db.' . $table;
            if (!$this->schemaTableExists($fullTable)) {
                throw new RuntimeException($fullTable . ' does not exist; run the legacy schema import first.');
            }

            if (!$this->schemaColumnExists($fullTable, 'author_user_id')) {
                $this->execute(
                    'ALTER TABLE ' . $this->identifier($this->schemaPrefix() . 'content_db') . '.`' . $table . '` '
                    . 'ADD COLUMN `author_user_id` BIGINT UNSIGNED NULL AFTER `id`',
                );
            }

            if (!$this->schemaColumnExists($fullTable, 'request_id')) {
                $this->execute(
                    'ALTER TABLE ' . $this->identifier($this->schemaPrefix() . 'content_db') . '.`' . $table . '` '
                    . 'ADD COLUMN `request_id` VARCHAR(64) NULL AFTER `author_user_id`',
                );
            }

            if (!$this->schemaIndexExists($fullTable, 'idx_author_user_created')) {
                $this->execute(
                    'ALTER TABLE ' . $this->identifier($this->schemaPrefix() . 'content_db') . '.`' . $table . '` '
                    . 'ADD INDEX `idx_author_user_created` (`author_user_id`, `created_at`)',
                );
            }

            if (!$this->schemaIndexExists($fullTable, 'uniq_author_request')) {
                $this->execute(
                    'ALTER TABLE ' . $this->identifier($this->schemaPrefix() . 'content_db') . '.`' . $table . '` '
                    . 'ADD UNIQUE INDEX `uniq_author_request` (`author_user_id`, `request_id`)',
                );
            }
            if (!$this->schemaIndexExists($fullTable, 'idx_alias_scope_exact')) {
                $this->execute(
                    'ALTER TABLE ' . $this->identifier($this->schemaPrefix() . 'content_db') . '.`' . $table . '` '
                    . 'ADD INDEX `idx_alias_scope_exact` (`author_scope_type`(32), `author_scope_id`(64), `author_anonymous_ref`(128))',
                );
            }
        }
    }

    /** @throws IrreversibleMigrationException */
    public function down(): void
    {
        $schema = $this->schemaPrefix() . 'content_db';
        foreach (['posts', 'comments'] as $table) {
            if (!$this->schemaTableExists('content_db.' . $table)) {
                continue;
            }
            if ($this->tableHasRows($schema, $table)) {
                throw new IrreversibleMigrationException(
                    'Cannot remove content author ownership from ' . $schema . '.' . $table . ' while rows exist.',
                );
            }
            if ($this->schemaIndexExists('content_db.' . $table, 'idx_author_user_created')) {
                $this->execute(
                    'ALTER TABLE ' . $this->identifier($schema) . '.`' . $table . '` '
                    . 'DROP INDEX `idx_author_user_created`',
                );
            }
            if ($this->schemaIndexExists('content_db.' . $table, 'uniq_author_request')) {
                $this->execute(
                    'ALTER TABLE ' . $this->identifier($schema) . '.`' . $table . '` '
                    . 'DROP INDEX `uniq_author_request`',
                );
            }
            if ($this->schemaIndexExists('content_db.' . $table, 'idx_alias_scope_exact')) {
                $this->execute(
                    'ALTER TABLE ' . $this->identifier($schema) . '.`' . $table . '` '
                    . 'DROP INDEX `idx_alias_scope_exact`',
                );
            }
            if ($this->schemaColumnExists('content_db.' . $table, 'author_user_id')) {
                $this->execute(
                    'ALTER TABLE ' . $this->identifier($schema) . '.`' . $table . '` '
                    . 'DROP COLUMN `author_user_id`',
                );
            }
            if ($this->schemaColumnExists('content_db.' . $table, 'request_id')) {
                $this->execute(
                    'ALTER TABLE ' . $this->identifier($schema) . '.`' . $table . '` '
                    . 'DROP COLUMN `request_id`',
                );
            }
        }
    }

    private function schemaPrefix(): string
    {
        $prefix = $this->getAdapter()->getOption('schema_prefix') ?? '';
        if (!is_string($prefix) || preg_match('/\A[a-zA-Z0-9_]*\z/', $prefix) !== 1) {
            throw new RuntimeException('schema_prefix must contain only ASCII letters, digits, and underscores.');
        }

        return $prefix;
    }

    private function identifier(string $value): string
    {
        return '`' . $value . '`';
    }

    private function schemaTableExists(string $table): bool
    {
        return $this->getAdapter()->hasTable($this->schemaPrefix() . ltrim($table, '`'));
    }

    private function schemaColumnExists(string $table, string $column): bool
    {
        return $this->getAdapter()->hasColumn($this->schemaPrefix() . ltrim($table, '`'), $column);
    }

    private function schemaIndexExists(string $table, string $index): bool
    {
        return $this->getAdapter()->hasIndexByName($this->schemaPrefix() . ltrim($table, '`'), $index);
    }

    private function tableHasRows(string $schema, string $table): bool
    {
        $statement = $this->getAdapter()->getConnection()->query(
            'SELECT 1 FROM ' . $this->identifier($schema) . '.`' . $table . '` LIMIT 1',
        );

        return $statement->fetchColumn() !== false;
    }
}
