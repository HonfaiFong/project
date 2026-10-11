<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * Makes the Social interaction tables an explicit, checked contract.
 *
 * The same definitions live in infra/docker/mysql/init/01-databases.sql so a
 * fresh installation and a replayed migration converge on the same schema.
 * Existing rows are never rewritten; incompatible drift is rejected before
 * any DDL is issued so an operator can repair it deliberately.
 */
final class AddSocialInteractions extends AbstractMigration
{
    /** @var array<string, array<string, array{definition:string, data_type:string, column_type:string}>> */
    private const COLUMNS = [
        'likes' => [
            'id' => ['definition' => 'BIGINT UNSIGNED NOT NULL', 'data_type' => 'bigint', 'column_type' => 'bigint unsigned'],
            'persona_id' => ['definition' => 'BIGINT UNSIGNED NOT NULL', 'data_type' => 'bigint', 'column_type' => 'bigint unsigned'],
            'target_type' => ['definition' => 'SMALLINT NOT NULL', 'data_type' => 'smallint', 'column_type' => 'smallint'],
            'target_id' => ['definition' => 'BIGINT UNSIGNED NOT NULL', 'data_type' => 'bigint', 'column_type' => 'bigint unsigned'],
            'created_at' => ['definition' => 'DATETIME(3) NOT NULL', 'data_type' => 'datetime', 'column_type' => 'datetime(3)'],
        ],
        'follows' => [
            'id' => ['definition' => 'BIGINT UNSIGNED NOT NULL', 'data_type' => 'bigint', 'column_type' => 'bigint unsigned'],
            'follower_persona_id' => ['definition' => 'BIGINT UNSIGNED NOT NULL', 'data_type' => 'bigint', 'column_type' => 'bigint unsigned'],
            'followee_persona_id' => ['definition' => 'BIGINT UNSIGNED NOT NULL', 'data_type' => 'bigint', 'column_type' => 'bigint unsigned'],
            'created_at' => ['definition' => 'DATETIME(3) NOT NULL', 'data_type' => 'datetime', 'column_type' => 'datetime(3)'],
        ],
        'blocks' => [
            'id' => ['definition' => 'BIGINT UNSIGNED NOT NULL', 'data_type' => 'bigint', 'column_type' => 'bigint unsigned'],
            'blocker_user_id' => ['definition' => 'BIGINT UNSIGNED NOT NULL', 'data_type' => 'bigint', 'column_type' => 'bigint unsigned'],
            'blocked_user_id' => ['definition' => 'BIGINT UNSIGNED NOT NULL', 'data_type' => 'bigint', 'column_type' => 'bigint unsigned'],
            'created_at' => ['definition' => 'DATETIME(3) NOT NULL', 'data_type' => 'datetime', 'column_type' => 'datetime(3)'],
        ],
    ];

    /** @var array<string, array<string, array{unique:bool, columns:list<string>}>> */
    private const INDEXES = [
        'likes' => [
            'uniq_persona_target' => ['unique' => true, 'columns' => ['persona_id', 'target_type', 'target_id']],
            'idx_target_created' => ['unique' => false, 'columns' => ['target_type', 'target_id', 'created_at']],
        ],
        'follows' => [
            'uniq_follow' => ['unique' => true, 'columns' => ['follower_persona_id', 'followee_persona_id']],
            'idx_followee_created' => ['unique' => false, 'columns' => ['followee_persona_id', 'created_at']],
        ],
        'blocks' => [
            'uniq_block' => ['unique' => true, 'columns' => ['blocker_user_id', 'blocked_user_id']],
            'idx_blocked_created' => ['unique' => false, 'columns' => ['blocked_user_id', 'created_at']],
        ],
    ];

    /** @var array<string, array<string, string>> */
    private const CHECKS = [
        'likes' => [
            'chk_like_target_type' => 'target_type IN (1, 2)',
        ],
        'follows' => [
            'chk_follow_not_self' => 'follower_persona_id <> followee_persona_id',
        ],
        'blocks' => [
            'chk_block_not_self' => 'blocker_user_id <> blocked_user_id',
        ],
    ];

    public function up(): void
    {
        $pdo = $this->pdo();
        $schema = $this->schemaName();
        /** @var list<array{table:string, clauses:list<string>}> $plans */
        $plans = [];

        foreach (self::COLUMNS as $table => $columns) {
            if (!$this->tableExists($pdo, $schema, $table)) {
                throw new RuntimeException(
                    $schema . '.' . $table . ' does not exist; run the legacy schema import first.',
                );
            }

            $existingColumns = $this->columns($pdo, $schema, $table);
            $clauses = [];
            foreach ($columns as $name => $expected) {
                if (!isset($existingColumns[$name])) {
                    $clauses[] = 'ADD COLUMN `' . $name . '` ' . $expected['definition'];
                    continue;
                }
                if (!$this->isExpectedColumn($existingColumns[$name], $expected)) {
                    throw new RuntimeException(
                        $schema . '.' . $table . '.' . $name
                        . ' exists but has an incompatible type or nullability; repair it manually.',
                    );
                }
            }

            $existingIndexes = $this->indexes($pdo, $schema, $table);
            foreach (self::INDEXES[$table] as $name => $expected) {
                if (!isset($existingIndexes[$name])) {
                    $kind = $expected['unique'] ? 'UNIQUE KEY ' : 'INDEX ';
                    $clauses[] = $kind . '`' . $name . '` (`'
                        . implode('`, `', $expected['columns']) . '`)';
                    continue;
                }
                if (!$this->isExpectedIndex($existingIndexes[$name], $expected['unique'], $expected['columns'])) {
                    throw new RuntimeException(
                        $schema . '.' . $table . '.' . $name
                        . ' exists but has an unexpected definition; repair it manually.',
                    );
                }
            }

            $existingChecks = $this->checks($pdo, $schema, $table);
            foreach (self::CHECKS[$table] as $name => $expression) {
                if (!isset($existingChecks[$name])) {
                    $clauses[] = 'ADD CONSTRAINT `' . $name . '` CHECK (`' . $this->quoteExpression($expression) . '`)';
                    continue;
                }
                if (!$this->isExpectedCheck($existingChecks[$name], $expression)) {
                    throw new RuntimeException(
                        $schema . '.' . $table . '.' . $name
                        . ' exists but is not the enforced expected CHECK constraint; repair it manually.',
                    );
                }
            }

            $plans[] = ['table' => $table, 'clauses' => $clauses];
        }

        // Validate every table before issuing DDL. MySQL DDL auto-commits, so
        // a later drift failure must not leave an earlier table half-repaired.
        foreach ($plans as $plan) {
            if ($plan['clauses'] === []) {
                continue;
            }

            $this->execute(
                'ALTER TABLE ' . $this->identifier($schema) . '.`' . $plan['table'] . '` '
                . implode(', ', $plan['clauses']),
            );
        }
    }

    /** @throws IrreversibleMigrationException */
    public function down(): void
    {
        throw new IrreversibleMigrationException(
            'Social interaction history cannot be removed by rollback.',
        );
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

    /** @return array<string, list<array<string, mixed>>> */
    private function indexes(PDO $pdo, string $schema, string $table): array
    {
        $statement = $pdo->prepare(
            'SELECT `INDEX_NAME` AS index_name, `NON_UNIQUE` AS non_unique, '
            . '`SEQ_IN_INDEX` AS seq_in_index, `COLUMN_NAME` AS column_name, '
            . '`INDEX_TYPE` AS index_type FROM `information_schema`.`statistics` '
            . 'WHERE `TABLE_SCHEMA` = :schema AND `TABLE_NAME` = :table',
        );
        $statement->execute(['schema' => $schema, 'table' => $table]);
        $indexes = [];
        while ($index = $statement->fetch(PDO::FETCH_ASSOC)) {
            $indexes[(string)$index['index_name']][] = $index;
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

    /** @return array<string, array{check_clause:string, enforced:string}> */
    private function checks(PDO $pdo, string $schema, string $table): array
    {
        $statement = $pdo->prepare(
            'SELECT tc.`CONSTRAINT_NAME` AS constraint_name, cc.`CHECK_CLAUSE` AS check_clause, '
            . 'COALESCE(tc.`ENFORCED`, \'YES\') AS enforced '
            . 'FROM `information_schema`.`table_constraints` tc '
            . 'INNER JOIN `information_schema`.`check_constraints` cc '
            . 'ON cc.`CONSTRAINT_SCHEMA` = tc.`CONSTRAINT_SCHEMA` '
            . 'AND cc.`CONSTRAINT_NAME` = tc.`CONSTRAINT_NAME` '
            . 'WHERE tc.`TABLE_SCHEMA` = :schema AND tc.`TABLE_NAME` = :table '
            . 'AND tc.`CONSTRAINT_TYPE` = \'CHECK\'',
        );
        $statement->execute(['schema' => $schema, 'table' => $table]);
        $checks = [];
        while ($check = $statement->fetch(PDO::FETCH_ASSOC)) {
            $checks[(string)$check['constraint_name']] = [
                'check_clause' => (string)$check['check_clause'],
                'enforced' => (string)$check['enforced'],
            ];
        }

        return $checks;
    }

    /**
     * @param array<string, mixed> $column
     * @param array{data_type:string,column_type:string} $expected
     */
    private function isExpectedColumn(array $column, array $expected): bool
    {
        return strtolower((string)$column['data_type']) === $expected['data_type']
            && strtolower((string)$column['column_type']) === $expected['column_type']
            && strtoupper((string)$column['is_nullable']) === 'NO'
            && $column['column_default'] === null;
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

    /** @param array{check_clause:string,enforced:string} $check */
    private function isExpectedCheck(array $check, string $expression): bool
    {
        return strtoupper($check['enforced']) === 'YES'
            && $this->normalizeDefinition($check['check_clause']) === $this->normalizeDefinition($expression);
    }

    private function normalizeDefinition(string $definition): string
    {
        $normalized = strtolower(trim($definition));
        $normalized = str_replace('`', '', $normalized);
        $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;

        return str_replace(['(', ')'], '', $normalized);
    }

    private function quoteExpression(string $expression): string
    {
        // Expressions are internal constants. Quote only the known column
        // identifiers; SQL keywords such as IN must remain keywords.
        return str_replace(
            ['target_type', 'follower_persona_id', 'followee_persona_id', 'blocker_user_id', 'blocked_user_id'],
            ['`target_type`', '`follower_persona_id`', '`followee_persona_id`', '`blocker_user_id`', '`blocked_user_id`'],
            $expression,
        );
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
        if (strlen($prefix) > 54) {
            throw new RuntimeException('schema_prefix exceeds MySQL identifier length.');
        }

        return $prefix;
    }

    private function schemaName(): string
    {
        return $this->schemaPrefix() . 'social_db';
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
