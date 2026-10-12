<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Enforces the invariant that an account has at most one main persona while
 * keeping soft-deleted main rows in the uniqueness domain.
 *
 * Existing invalid data is deliberately rejected. Operators must repair the
 * rows explicitly before rerunning this migration so that no identity is
 * silently reassigned by deployment.
 */
final class AddPersonaIdentityConstraints extends AbstractMigration
{
    private const TABLE = 'personas';
    private const MAIN_COLUMN = 'main_user_id';
    private const TYPE_CHECK = 'chk_persona_type';
    private const MAIN_UNIQUE = 'uniq_main_persona_user';
    private const USER_TYPE_INDEX = 'idx_user_type_status';

    public function up(): void
    {
        $pdo = $this->pdo();
        $schema = $this->schemaName($this->schemaPrefix());

        if (!$this->tableExists($pdo, $schema)) {
            throw new RuntimeException('user_db.personas does not exist; run the legacy schema import first.');
        }

        $this->assertHistoricalDataIsValid($pdo, $schema);

        $clauses = [];
        $columns = $this->columns($pdo, $schema);
        if (!isset($columns[self::MAIN_COLUMN])) {
            $clauses[] = 'ADD COLUMN `main_user_id` BIGINT UNSIGNED GENERATED ALWAYS AS '
                . '(CASE WHEN `type` = 1 THEN `user_id` ELSE NULL END) STORED';
        } elseif (!$this->isExpectedMainColumn($columns[self::MAIN_COLUMN])) {
            throw new RuntimeException(
                'user_db.personas.main_user_id exists but is not the expected generated column; repair it manually.',
            );
        }

        $constraints = $this->constraints($pdo, $schema);
        if (isset($constraints[self::TYPE_CHECK])) {
            if (!$this->isExpectedTypeCheck($constraints[self::TYPE_CHECK])) {
                throw new RuntimeException(
                    'user_db.personas.chk_persona_type exists but does not enforce type IN (1, 2); repair it manually.',
                );
            }
        } else {
            $clauses[] = 'ADD CONSTRAINT `chk_persona_type` CHECK (`type` IN (1, 2))';
        }

        $indexes = $this->indexes($pdo, $schema);
        if (isset($indexes[self::MAIN_UNIQUE])) {
            if (!$this->isExpectedIndex($indexes[self::MAIN_UNIQUE], true, ['main_user_id'])) {
                throw new RuntimeException(
                    'user_db.personas.uniq_main_persona_user exists but is not a unique index on main_user_id; repair it manually.',
                );
            }
        } else {
            $clauses[] = 'ADD UNIQUE KEY `uniq_main_persona_user` (`main_user_id`)';
        }
        if (isset($indexes[self::USER_TYPE_INDEX])) {
            if (!$this->isExpectedIndex(
                $indexes[self::USER_TYPE_INDEX],
                false,
                ['user_id', 'type', 'status', 'deleted_at'],
            )) {
                throw new RuntimeException(
                    'user_db.personas.idx_user_type_status has an unexpected definition; repair it manually.',
                );
            }
        } else {
            $clauses[] = 'ADD INDEX `idx_user_type_status` (`user_id`, `type`, `status`, `deleted_at`)';
        }

        if ($clauses !== []) {
            // All additions are issued as one ALTER after the history checks;
            // a failed preflight therefore cannot leave half of the invariant.
            $this->execute(
                'ALTER TABLE ' . $this->identifier($schema) . '.`personas` ' . implode(', ', $clauses),
            );
        }
    }

    public function down(): void
    {
        $pdo = $this->pdo();
        $schema = $this->schemaName($this->schemaPrefix());
        if (!$this->tableExists($pdo, $schema)) {
            return;
        }

        $clauses = [];
        $indexes = $this->indexes($pdo, $schema);
        if (isset($indexes[self::MAIN_UNIQUE])) {
            $clauses[] = 'DROP INDEX `uniq_main_persona_user`';
        }
        if (isset($indexes[self::USER_TYPE_INDEX])) {
            $clauses[] = 'DROP INDEX `idx_user_type_status`';
        }

        $constraints = $this->constraints($pdo, $schema);
        if (isset($constraints[self::TYPE_CHECK])) {
            $clauses[] = 'DROP CHECK `chk_persona_type`';
        }

        $columns = $this->columns($pdo, $schema);
        if (isset($columns[self::MAIN_COLUMN])) {
            $clauses[] = 'DROP COLUMN `main_user_id`';
        }

        if ($clauses !== []) {
            $this->execute(
                'ALTER TABLE ' . $this->identifier($schema) . '.`personas` ' . implode(', ', $clauses),
            );
        }
    }

    private function assertHistoricalDataIsValid(PDO $pdo, string $schema): void
    {
        $duplicate = $pdo->prepare(
            'SELECT `user_id`, COUNT(*) AS `main_count` '
            . 'FROM ' . $this->identifier($schema) . '.`personas` '
            . 'WHERE `type` = 1 GROUP BY `user_id` HAVING COUNT(*) > 1 LIMIT 1',
        );
        $duplicate->execute();
        $row = $duplicate->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            throw new RuntimeException(
                'Cannot add main persona uniqueness: user_id ' . (string)$row['user_id']
                . ' has multiple type=1 personas. Repair history explicitly before rerunning.',
            );
        }

        $invalid = $pdo->prepare(
            'SELECT `id`, `type` FROM ' . $this->identifier($schema) . '.`personas` '
            . 'WHERE `type` IS NULL OR `type` NOT IN (1, 2) LIMIT 1',
        );
        $invalid->execute();
        $row = $invalid->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            throw new RuntimeException(
                'Cannot add persona type constraint: persona id ' . (string)$row['id']
                . ' has invalid type ' . (string)($row['type'] ?? 'NULL')
                . '. Repair history explicitly before rerunning.',
            );
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function columns(PDO $pdo, string $schema): array
    {
        $statement = $pdo->prepare(
            'SELECT `COLUMN_NAME` AS column_name, `DATA_TYPE` AS data_type, '
            . '`COLUMN_TYPE` AS column_type, `EXTRA` AS extra, '
            . '`GENERATION_EXPRESSION` AS generation_expression '
            . 'FROM `information_schema`.`columns` '
            . 'WHERE `TABLE_SCHEMA` = :schema AND `TABLE_NAME` = :table',
        );
        $statement->execute(['schema' => $schema, 'table' => self::TABLE]);

        $columns = [];
        while ($column = $statement->fetch(PDO::FETCH_ASSOC)) {
            $columns[(string)$column['column_name']] = $column;
        }

        return $columns;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function indexes(PDO $pdo, string $schema): array
    {
        $statement = $pdo->prepare(
            'SELECT `INDEX_NAME` AS index_name, `NON_UNIQUE` AS non_unique, '
            . '`SEQ_IN_INDEX` AS seq_in_index, `COLUMN_NAME` AS column_name, '
            . '`INDEX_TYPE` AS index_type FROM `information_schema`.`statistics` '
            . 'WHERE `TABLE_SCHEMA` = :schema AND `TABLE_NAME` = :table',
        );
        $statement->execute(['schema' => $schema, 'table' => self::TABLE]);

        $indexes = [];
        while ($index = $statement->fetch(PDO::FETCH_ASSOC)) {
            $name = (string)$index['index_name'];
            $indexes[$name][] = $index;
        }

        foreach ($indexes as &$index) {
            usort(
                $index,
                static fn (array $left, array $right): int =>
                    ((int)$left['seq_in_index']) <=> ((int)$right['seq_in_index']),
            );
        }
        unset($index);

        return $indexes;
    }

    /** @return array<string, array{check_clause: string, enforced: string}> */
    private function constraints(PDO $pdo, string $schema): array
    {
        $statement = $pdo->prepare(
            'SELECT tc.`CONSTRAINT_NAME` AS constraint_name, cc.`CHECK_CLAUSE` AS check_clause, '
            . 'tc.`ENFORCED` AS enforced FROM `information_schema`.`table_constraints` tc '
            . 'INNER JOIN `information_schema`.`check_constraints` cc '
            . 'ON cc.`CONSTRAINT_SCHEMA` = tc.`CONSTRAINT_SCHEMA` '
            . 'AND cc.`CONSTRAINT_NAME` = tc.`CONSTRAINT_NAME` '
            . 'WHERE tc.`TABLE_SCHEMA` = :schema AND tc.`TABLE_NAME` = :table '
            . 'AND tc.`CONSTRAINT_TYPE` = \'CHECK\'',
        );
        $statement->execute(['schema' => $schema, 'table' => self::TABLE]);

        $constraints = [];
        while ($constraint = $statement->fetch(PDO::FETCH_ASSOC)) {
            $constraints[(string)$constraint['constraint_name']] = [
                'check_clause' => (string)$constraint['check_clause'],
                'enforced' => (string)$constraint['enforced'],
            ];
        }

        return $constraints;
    }

    /** @param array<string, mixed> $column */
    private function isExpectedMainColumn(array $column): bool
    {
        $expression = $this->normalizeDefinition((string)$column['generation_expression']);

        return strtolower((string)$column['data_type']) === 'bigint'
            && str_contains(strtolower((string)$column['column_type']), 'unsigned')
            && str_contains(strtolower((string)$column['extra']), 'stored generated')
            && $expression === 'casewhentype=1thenuser_idelsenullend';
    }

    /** @param array{check_clause: string, enforced: string} $constraint */
    private function isExpectedTypeCheck(array $constraint): bool
    {
        return strtoupper($constraint['enforced']) === 'YES'
            && $this->normalizeDefinition($constraint['check_clause']) === 'typein1,2';
    }

    /**
     * @param list<array<string, mixed>> $index
     * @param list<string> $expectedColumns
     */
    private function isExpectedIndex(array $index, bool $unique, array $expectedColumns): bool
    {
        if (count($index) !== count($expectedColumns)) {
            return false;
        }

        foreach ($expectedColumns as $position => $column) {
            $definition = $index[$position] ?? null;
            if ($definition === null
                || (int)$definition['seq_in_index'] !== $position + 1
                || (string)($definition['column_name'] ?? '') !== $column
                || ((int)$definition['non_unique'] === 0) !== $unique
                || strtoupper((string)($definition['index_type'] ?? '')) !== 'BTREE'
            ) {
                return false;
            }
        }

        return true;
    }

    private function normalizeDefinition(string $definition): string
    {
        $normalized = strtolower(trim($definition));
        $normalized = str_replace('`', '', $normalized);
        $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;

        return str_replace(['(', ')'], '', $normalized);
    }

    private function tableExists(PDO $pdo, string $schema): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM `information_schema`.`tables` '
            . 'WHERE `TABLE_SCHEMA` = :schema AND `TABLE_NAME` = :table LIMIT 1',
        );
        $statement->execute(['schema' => $schema, 'table' => self::TABLE]);

        return $statement->fetchColumn() !== false;
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

    private function schemaName(string $prefix): string
    {
        $schema = $prefix . 'user_db';
        if (strlen($schema) > 64) {
            throw new RuntimeException('Database name exceeds MySQL identifier length: ' . $schema);
        }

        return $schema;
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
