<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * Makes the server-owned anonymous identity explicit without rewriting old
 * alias rows. Existing aliases receive is_internal_alias=0 and remain useful
 * only for historical ownership/audit lookups.
 */
final class AddInternalAliasIdentity extends AbstractMigration
{
    private const TABLE = 'personas';
    private const FLAG = 'is_internal_alias';
    private const GENERATED = 'alias_user_id';
    private const UNIQUE = 'uniq_internal_alias_user';
    private const CHECK = 'chk_internal_alias_type';

    public function up(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        $schema = $this->schemaName();
        if (!$this->tableExists($pdo, $schema)) {
            throw new RuntimeException('user_db.personas does not exist; run the legacy schema import first.');
        }

        $columns = $this->columns($pdo, $schema);
        if (!isset($columns[self::FLAG])) {
            $this->execute(
                'ALTER TABLE ' . $this->identifier($schema) . '.`personas` '
                . 'ADD COLUMN `is_internal_alias` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `type`',
            );
        } elseif (!$this->isExpectedFlagColumn($columns[self::FLAG])) {
            // A partially applied version of this migration used signed
            // TINYINT(1). Convert that exact, binary-safe shape in place so a
            // failed deployment can be retried without silently changing
            // identity semantics. Other definitions remain a hard failure.
            if ($this->isLegacySignedFlagColumn($columns[self::FLAG])
                && $this->flagValuesAreBinary($pdo, $schema)
            ) {
                $this->execute(
                    'ALTER TABLE ' . $this->identifier($schema) . '.`personas` '
                    . 'MODIFY COLUMN `is_internal_alias` TINYINT UNSIGNED NOT NULL DEFAULT 0',
                );
            } else {
                throw new RuntimeException('personas.is_internal_alias has an incompatible type, nullability, or default.');
            }
        }

        $duplicate = $pdo->query(
            'SELECT `user_id`, COUNT(*) AS `alias_count` FROM '
            . $this->identifier($schema) . '.`personas` '
            . 'WHERE `type` = 2 AND `is_internal_alias` = 1 '
            . 'GROUP BY `user_id` HAVING COUNT(*) > 1 LIMIT 1',
        )->fetch(PDO::FETCH_ASSOC);
        if ($duplicate !== false) {
            throw new RuntimeException(
                'Cannot add internal alias uniqueness: user_id ' . (string)$duplicate['user_id']
                . ' has multiple canonical aliases. Repair history explicitly before rerunning.',
            );
        }

        $columns = $this->columns($pdo, $schema);
        if (!isset($columns[self::GENERATED])) {
            $this->execute(
                'ALTER TABLE ' . $this->identifier($schema) . '.`personas` '
                . 'ADD COLUMN `alias_user_id` BIGINT UNSIGNED GENERATED ALWAYS AS '
                . '(CASE WHEN `type` = 2 AND `is_internal_alias` = 1 THEN `user_id` ELSE NULL END) STORED',
            );
        } elseif (!$this->isExpectedGeneratedColumn($columns[self::GENERATED])) {
            throw new RuntimeException('personas.alias_user_id is not the expected generated column; repair it manually.');
        }

        $indexes = $this->indexes($pdo, $schema);
        if (!isset($indexes[self::UNIQUE])) {
            $this->execute(
                'ALTER TABLE ' . $this->identifier($schema) . '.`personas` '
                . 'ADD UNIQUE KEY `uniq_internal_alias_user` (`alias_user_id`)',
            );
        } elseif (!$this->isUniqueSingleColumn($indexes[self::UNIQUE], self::GENERATED)) {
            throw new RuntimeException('personas.uniq_internal_alias_user is not a unique index on alias_user_id.');
        }

        $checks = $this->checks($pdo, $schema);
        if (!isset($checks[self::CHECK])) {
            $this->execute(
                'ALTER TABLE ' . $this->identifier($schema) . '.`personas` '
                . 'ADD CONSTRAINT `chk_internal_alias_type` CHECK '
                . '(`is_internal_alias` IN (0, 1) AND (`is_internal_alias` = 0 OR `type` = 2))',
            );
        } elseif (!$this->isExpectedCheck($checks[self::CHECK])) {
            throw new RuntimeException('personas.chk_internal_alias_type is not the enforced expected CHECK constraint.');
        }
    }

    /** @throws IrreversibleMigrationException */
    public function down(): void
    {
        throw new IrreversibleMigrationException(
            'The canonical alias marker is security history and cannot be removed by rollback.',
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function columns(PDO $pdo, string $schema): array
    {
        $statement = $pdo->prepare(
            'SELECT `COLUMN_NAME` AS column_name, `DATA_TYPE` AS data_type, '
            . '`COLUMN_TYPE` AS column_type, `IS_NULLABLE` AS is_nullable, '
            . '`COLUMN_DEFAULT` AS column_default, `EXTRA` AS extra, '
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
            . '`SEQ_IN_INDEX` AS seq_in_index, `COLUMN_NAME` AS column_name '
            . 'FROM `information_schema`.`statistics` '
            . 'WHERE `TABLE_SCHEMA` = :schema AND `TABLE_NAME` = :table',
        );
        $statement->execute(['schema' => $schema, 'table' => self::TABLE]);
        $indexes = [];
        while ($index = $statement->fetch(PDO::FETCH_ASSOC)) {
            $indexes[(string)$index['index_name']][] = $index;
        }
        foreach ($indexes as &$index) {
            usort($index, static fn (array $a, array $b): int => (int)$a['seq_in_index'] <=> (int)$b['seq_in_index']);
        }
        unset($index);

        return $indexes;
    }

    /** @return array<string, array{check_clause: string, enforced: string}> */
    private function checks(PDO $pdo, string $schema): array
    {
        $statement = $pdo->prepare(
            'SELECT cc.`CONSTRAINT_NAME` AS constraint_name, cc.`CHECK_CLAUSE` AS check_clause, '
            . 'COALESCE(tc.`ENFORCED`, \'YES\') AS enforced '
            . 'FROM `information_schema`.`check_constraints` cc '
            . 'INNER JOIN `information_schema`.`table_constraints` tc '
            . 'ON tc.`CONSTRAINT_SCHEMA` = cc.`CONSTRAINT_SCHEMA` '
            . 'AND tc.`CONSTRAINT_NAME` = cc.`CONSTRAINT_NAME` '
            . 'WHERE tc.`TABLE_SCHEMA` = :schema AND tc.`TABLE_NAME` = :table '
            . 'AND tc.`CONSTRAINT_TYPE` = \'CHECK\'',
        );
        $statement->execute(['schema' => $schema, 'table' => self::TABLE]);
        $checks = [];
        while ($check = $statement->fetch(PDO::FETCH_ASSOC)) {
            $checks[(string)$check['constraint_name']] = [
                'check_clause' => (string)$check['check_clause'],
                'enforced' => (string)$check['enforced'],
            ];
        }

        return $checks;
    }

    /** @param array<string, mixed> $column */
    private function isExpectedGeneratedColumn(array $column): bool
    {
        $expression = strtolower((string)$column['generation_expression']);
        $expression = preg_replace('/\s+|`|\(|\)/', '', $expression) ?? $expression;

        return strtolower((string)$column['data_type']) === 'bigint'
            && str_contains(strtolower((string)$column['column_type']), 'unsigned')
            && str_contains(strtolower((string)$column['extra']), 'stored generated')
            && $expression === 'casewhentype=2andis_internal_alias=1thenuser_idelsenullend';
    }

    /** @param array<string, mixed> $column */
    private function isExpectedFlagColumn(array $column): bool
    {
        return strtolower((string)$column['data_type']) === 'tinyint'
            && strtolower((string)$column['column_type']) === 'tinyint unsigned'
            && strtoupper((string)$column['is_nullable']) === 'NO'
            && (string)$column['column_default'] === '0';
    }

    /** @param array<string, mixed> $column */
    private function isLegacySignedFlagColumn(array $column): bool
    {
        return strtolower((string)$column['data_type']) === 'tinyint'
            && strtolower((string)$column['column_type']) === 'tinyint(1)'
            && strtoupper((string)$column['is_nullable']) === 'NO'
            && (string)$column['column_default'] === '0';
    }

    private function flagValuesAreBinary(PDO $pdo, string $schema): bool
    {
        $statement = $pdo->query(
            'SELECT 1 FROM ' . $this->identifier($schema) . '.`personas` '
            . 'WHERE `is_internal_alias` NOT IN (0, 1) LIMIT 1',
        );

        return $statement->fetchColumn() === false;
    }

    /** @param array{check_clause: string, enforced: string} $check */
    private function isExpectedCheck(array $check): bool
    {
        $normalized = strtolower($check['check_clause']);
        $normalized = str_replace('`', '', $normalized);
        $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;

        // MySQL may add redundant grouping around individual predicates and
        // around the whole expression. Remove only those harmless wrappers;
        // the parentheses around the OR branch remain part of the contract.
        do {
            $previous = $normalized;
            $normalized = preg_replace(
                '/\((is_internal_aliasin\(0,1\)|is_internal_alias=0|type=2)\)/',
                '$1',
                $normalized,
            ) ?? $normalized;
            $normalized = $this->stripOuterParentheses($normalized);
        } while ($normalized !== $previous);

        return strtoupper($check['enforced']) === 'YES'
            && $normalized === 'is_internal_aliasin(0,1)and(is_internal_alias=0ortype=2)';
    }

    private function stripOuterParentheses(string $value): string
    {
        while (str_starts_with($value, '(') && str_ends_with($value, ')')) {
            $depth = 0;
            $wrapsWholeExpression = true;
            $length = strlen($value);
            for ($index = 0; $index < $length; $index++) {
                $character = $value[$index];
                if ($character === '(') {
                    $depth++;
                } elseif ($character === ')') {
                    $depth--;
                    if ($depth === 0 && $index < $length - 1) {
                        $wrapsWholeExpression = false;
                        break;
                    }
                }
            }
            if (!$wrapsWholeExpression || $depth !== 0) {
                break;
            }
            $value = substr($value, 1, -1);
        }

        return $value;
    }

    /** @param list<array<string, mixed>> $index */
    private function isUniqueSingleColumn(array $index, string $column): bool
    {
        return count($index) === 1
            && (int)$index[0]['non_unique'] === 0
            && (int)$index[0]['seq_in_index'] === 1
            && (string)$index[0]['column_name'] === $column;
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

    private function schemaName(): string
    {
        $prefix = $this->getAdapter()->getOption('schema_prefix') ?? '';
        if (!is_string($prefix) || preg_match('/\A[a-zA-Z0-9_]*\z/', $prefix) !== 1) {
            throw new RuntimeException('schema_prefix must contain only ASCII letters, digits, and underscores.');
        }

        return $prefix . 'user_db';
    }

    private function identifier(string $identifier): string
    {
        return '`' . $identifier . '`';
    }
}
