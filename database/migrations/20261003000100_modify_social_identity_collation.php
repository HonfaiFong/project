<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class ModifySocialIdentityCollation extends AbstractMigration
{
    /** @var array<string, array{length: int, nullable: bool}> */
    private const COLUMNS = [
        'provider_app_id' => ['length' => 64, 'nullable' => false],
        'identity_namespace' => ['length' => 64, 'nullable' => false],
        'subject_id' => ['length' => 128, 'nullable' => false],
        'openid' => ['length' => 128, 'nullable' => false],
        'unionid' => ['length' => 128, 'nullable' => true],
    ];

    public function up(): void
    {
        $pdo = $this->pdo();
        $schema = $this->schemaName($this->schemaPrefix());
        $columns = $this->loadColumns($pdo, $schema);

        if ($columns === []) {
            throw new RuntimeException(
                'auth_db.social_identities does not exist; run the legacy schema import first.',
            );
        }

        $modifications = [];
        foreach (self::COLUMNS as $name => $definition) {
            $column = $columns[$name] ?? null;
            if ($column === null) {
                throw new RuntimeException(
                    'auth_db.social_identities is missing expected column: ' . $name,
                );
            }

            if (!$this->needsChange($column, $definition)) {
                continue;
            }

            $nullability = $definition['nullable'] ? 'NULL' : 'NOT NULL';
            $modifications[] = sprintf(
                'MODIFY COLUMN `%s` VARCHAR(%d) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin %s',
                $name,
                $definition['length'],
                $nullability,
            );
        }

        if ($modifications === []) {
            return;
        }

        // 这是跨数据库的条件 ALTER；通过 Phinx 的 execute() 执行原生 SQL，以保留演练模式和适配器的行为。
        $this->execute(
            'ALTER TABLE ' . $this->identifier($schema) . '.`social_identities` '
            . implode(', ', $modifications),
        );
    }

    /** @throws IrreversibleMigrationException */
    public function down(): void
    {
        throw new IrreversibleMigrationException(
            'Changing identity identifier collations cannot be reversed without a data contract.',
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function loadColumns(PDO $pdo, string $schema): array
    {
        $statement = $pdo->prepare(
            'SELECT `COLUMN_NAME` AS column_name, `DATA_TYPE` AS data_type, '
            . '`CHARACTER_MAXIMUM_LENGTH` AS character_maximum_length, '
            . '`CHARACTER_SET_NAME` AS character_set_name, `COLLATION_NAME` AS collation_name, '
            . '`IS_NULLABLE` AS is_nullable '
            . 'FROM `information_schema`.`columns` '
            . 'WHERE `table_schema` = :table_schema '
            . 'AND `table_name` = :table_name',
        );
        $statement->execute([
            'table_schema' => $schema,
            'table_name' => 'social_identities',
        ]);

        $columns = [];
        while ($column = $statement->fetch(PDO::FETCH_ASSOC)) {
            $columns[(string)$column['column_name']] = $column;
        }

        return $columns;
    }

    /** @param array<string, mixed> $column */
    private function needsChange(array $column, array $definition): bool
    {
        return strtolower((string)$column['data_type']) !== 'varchar'
            || (int)$column['character_maximum_length'] !== $definition['length']
            || strtolower((string)$column['character_set_name']) !== 'utf8mb4'
            || strtolower((string)$column['collation_name']) !== 'utf8mb4_bin'
            || (($column['is_nullable'] === 'YES') !== $definition['nullable']);
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
        $schema = $prefix . 'auth_db';
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
