<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class ImportLegacySchema extends AbstractMigration
{
    /** @var list<string> */
    private const LOGICAL_DATABASES = [
        'auth_db',
        'user_db',
        'content_db',
        'social_db',
        'chat_db',
        'message_db',
        'meta',
    ];

    /** @var list<string> */
    private const OUTBOX_DATABASES = [
        'auth_db',
        'user_db',
        'content_db',
        'social_db',
        'chat_db',
        'message_db',
    ];

    /** @var list<string> */
    private const LEGACY_MIGRATIONS = [
        '0001_outbox_claimed_at.sql',
        '0002_outbox_aggregate_order.sql',
        '0003_outbox_event_metadata.sql',
        '0004_outbox_event_key_index.sql',
        '0005_social_identities.sql',
        '0006_device_user_scope.sql',
    ];

    public function up(): void
    {
        $pdo = $this->pdo();
        $schemas = $this->schemas($this->schemaPrefix());
        $meta = $schemas['meta'];

        // The baseline and legacy files retain historical multi-statement USE
        // and prepared SQL while the repair steps span six logical databases,
        // so the migration adapter executes native SQL with validated identifiers.
        $this->executeBaseline($schemas, $meta);
        $this->useSchema($meta);
        $this->createLegacyMigrationTable($meta);

        foreach (self::LEGACY_MIGRATIONS as $name) {
            if ($this->legacyMigrationApplied($pdo, $meta, $name)) {
                continue;
            }

            $this->applyLegacyMigration($schemas, $meta, $name);
            $this->recordLegacyMigration($meta, $name);
        }

        $this->useSchema($meta);
    }

    /** @throws IrreversibleMigrationException */
    public function down(): void
    {
        throw new IrreversibleMigrationException(
            'Importing the legacy schema and applying its migrations cannot be reversed safely.',
        );
    }

    private function applyLegacyMigration(array $schemas, string $meta, string $name): void
    {
        match ($name) {
            '0001_outbox_claimed_at.sql' => $this->repairOutboxClaimedAt($schemas),
            '0002_outbox_aggregate_order.sql' => $this->repairOutboxIndex(
                $schemas,
                'idx_aggregate_order',
                '(`aggregate_type`, `aggregate_id`, `id`, `status`)',
            ),
            '0003_outbox_event_metadata.sql' => $this->repairEventMetadata($schemas),
            '0004_outbox_event_key_index.sql' => $this->repairOutboxIndex(
                $schemas,
                'idx_event_key_id_status',
                '(`event_key`, `id`, `status`)',
            ),
            '0005_social_identities.sql', '0006_device_user_scope.sql' => $this->executeLegacySql(
                $schemas,
                $meta,
                $name,
            ),
            default => throw new RuntimeException('Unknown legacy migration: ' . $name),
        };
    }

    private function repairOutboxClaimedAt(array $schemas): void
    {
        foreach (self::OUTBOX_DATABASES as $database) {
            $schema = $schemas[$database];
            if ($this->columnExists($schema, 'claimed_at')) {
                continue;
            }

            $this->execute(
                'ALTER TABLE ' . $this->identifier($schema) . '.`outbox_events` '
                . 'ADD COLUMN `claimed_at` DATETIME(3) NULL AFTER `available_at`',
            );
        }
    }

    private function repairOutboxIndex(array $schemas, string $index, string $definition): void
    {
        foreach (self::OUTBOX_DATABASES as $database) {
            $schema = $schemas[$database];
            if ($this->indexExists($schema, $index)) {
                continue;
            }

            $this->execute(
                'ALTER TABLE ' . $this->identifier($schema) . '.`outbox_events` '
                . 'ADD INDEX ' . $this->identifier($index) . ' ' . $definition,
            );
        }
    }

    private function repairEventMetadata(array $schemas): void
    {
        foreach (self::OUTBOX_DATABASES as $database) {
            $schema = $schemas[$database];

            if (!$this->columnExists($schema, 'event_key')) {
                $this->execute(
                    'ALTER TABLE ' . $this->identifier($schema) . '.`outbox_events` '
                    . 'ADD COLUMN `event_key` VARCHAR(64) NOT NULL DEFAULT \'\' AFTER `aggregate_id`',
                );
            }

            if (!$this->columnExists($schema, 'trace_id')) {
                $this->execute(
                    'ALTER TABLE ' . $this->identifier($schema) . '.`outbox_events` '
                    . 'ADD COLUMN `trace_id` VARCHAR(64) NOT NULL DEFAULT \'\' AFTER `event_key`',
                );
            }

            // Keep the historical 0003 backfill, but only fill rows that still
            // have the new column's default. This makes a retry safe if a
            // worker wrote an event between the partial DDL and this UPDATE.
            $assignment = $database === 'user_db'
                ? '`event_key` = CASE '
                    . 'WHEN `event_type` = \'persona.updated.v1\' '
                    . 'AND JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.user_id\')) IS NOT NULL '
                    . 'THEN JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.user_id\')) '
                    . 'ELSE `aggregate_id` END'
                : '`event_key` = `aggregate_id`';
            $this->execute(
                'UPDATE ' . $this->identifier($schema) . '.`outbox_events` '
                . 'SET ' . $assignment . ' WHERE `event_key` = \'\'',
            );
        }
    }

    private function executeLegacySql(array $schemas, string $meta, string $name): void
    {
        $path = __DIR__ . '/../../infra/docker/mysql/migrations/' . $name;
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Unable to read legacy migration: ' . $name);
        }

        $sql = $this->prefixDatabaseNames($sql, $schemas);

        try {
            $this->execute($sql);
        } finally {
            // 0005/0006 contain USE auth_db. Restore meta before the legacy
            // record is queried or written, including on an execution error.
            $this->useSchema($meta);
        }
    }

    private function executeBaseline(array $schemas, string $meta): void
    {
        $sql = file_get_contents(__DIR__ . '/../../infra/docker/mysql/init/01-databases.sql');
        if ($sql === false) {
            throw new RuntimeException('Unable to read the database baseline SQL.');
        }

        // Database privileges are deployment policy, not application schema.
        // Do not let a migration grant privileges or alter the root account.
        $sqlWithoutGrant = preg_replace('/^GRANT ALL PRIVILEGES.*\R/m', '', $sql);
        if ($sqlWithoutGrant === null) {
            throw new RuntimeException('Unable to remove baseline privilege statements.');
        }
        $sql = preg_replace('/^FLUSH PRIVILEGES;\s*\R?/m', '', $sqlWithoutGrant);
        if ($sql === null) {
            throw new RuntimeException('Unable to remove baseline privilege flush.');
        }
        $sql = $this->prefixDatabaseNames($sql, $schemas);

        try {
            $this->execute($sql);
        } finally {
            $this->useSchema($meta);
        }
    }

    private function createLegacyMigrationTable(string $meta): void
    {
        $this->execute(
            'CREATE TABLE IF NOT EXISTS ' . $this->identifier($meta) . '.`schema_migrations` ('
            . '`name` VARCHAR(255) NOT NULL PRIMARY KEY, '
            . '`applied_at` DATETIME(3) NOT NULL'
            . ') ENGINE=InnoDB',
        );
    }

    private function legacyMigrationApplied(PDO $pdo, string $meta, string $name): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM ' . $this->identifier($meta) . '.`schema_migrations` '
            . 'WHERE `name` = :name LIMIT 1',
        );
        $statement->execute(['name' => $name]);

        return $statement->fetchColumn() !== false;
    }

    private function recordLegacyMigration(string $meta, string $name): void
    {
        $this->execute(
            'INSERT INTO ' . $this->identifier($meta)
            . '.`schema_migrations` (`name`, `applied_at`) '
            . 'VALUES (:name, CURRENT_TIMESTAMP(3))',
            ['name' => $name],
        );
    }

    private function columnExists(string $schema, string $column): bool
    {
        return $this->getAdapter()->hasColumn($schema . '.outbox_events', $column);
    }

    private function indexExists(string $schema, string $index): bool
    {
        return $this->getAdapter()->hasIndexByName($schema . '.outbox_events', $index);
    }

    /** @return array<string, string> */
    private function schemas(string $prefix): array
    {
        $schemas = [];
        foreach (self::LOGICAL_DATABASES as $database) {
            $schema = $prefix . $database;
            if (strlen($schema) > 64) {
                throw new RuntimeException('Database name exceeds MySQL identifier length: ' . $schema);
            }
            $schemas[$database] = $schema;
        }

        return $schemas;
    }

    private function schemaPrefix(): string
    {
        $prefix = $this->getAdapter()->getOption('schema_prefix');
        if ($prefix === null) {
            return '';
        }
        if (!is_string($prefix) || preg_match('/\A[a-zA-Z0-9_]*\z/', $prefix) !== 1) {
            throw new RuntimeException('schema_prefix must contain only ASCII letters, digits, and underscores.');
        }
        if (strlen($prefix) > 64) {
            throw new RuntimeException('schema_prefix exceeds MySQL identifier length.');
        }

        return $prefix;
    }

    private function prefixDatabaseNames(string $sql, array $schemas): string
    {
        foreach (self::LOGICAL_DATABASES as $database) {
            $sql = str_replace(
                '`' . $database . '`',
                $this->identifier($schemas[$database]),
                $sql,
            );
        }

        return $sql;
    }

    private function useSchema(string $schema): void
    {
        $this->execute('USE ' . $this->identifier($schema));
    }

    private function identifier(string $identifier): string
    {
        return '`' . $identifier . '`';
    }

    private function pdo(): PDO
    {
        return $this->getAdapter()->getConnection();
    }
}
