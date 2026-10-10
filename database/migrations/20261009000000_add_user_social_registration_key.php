<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;
use Phinx\Db\Table\Column;

/**
 * 在新建用户行中持久化 `user.v1.UserService/FindOrCreateBySocialIdentity` 使用的、由调用方持有的注册键。
 *
 * 该列允许为空并具有唯一约束：MySQL 的唯一索引允许多个 NULL，因此现有手机或邮箱用户不受影响；同一键的重复注册或
 * 重试无法创建第二个账户。唯一约束会拒绝竞争插入，服务随后重新读取成功插入的行。
 */
final class AddUserSocialRegistrationKey extends AbstractMigration
{
    private const COLUMN = 'social_registration_key';
    private const INDEX = 'uniq_social_registration_key';

    public function up(): void
    {
        $schema = $this->schemaName($this->schemaPrefix());
        $table = $this->table($schema . '.users');
        if (!$table->exists()) {
            throw new RuntimeException('user_db.users does not exist; run the legacy schema import first.');
        }

        $changed = false;
        if (!$table->hasColumn(self::COLUMN)) {
            $column = (new Column())->setName(self::COLUMN)->setType(Column::STRING)
                ->setLimit(128)->setNull(true)->setEncoding('utf8mb4')
                ->setCollation('utf8mb4_bin')->setAfter('register_channel');
            $table->addColumn($column);
            $changed = true;
        }
        if (!$table->hasIndexByName(self::INDEX)) {
            $table->addIndex([self::COLUMN], ['unique' => true, 'name' => self::INDEX]);
            $changed = true;
        }
        if ($changed) {
            $table->update();
        }
    }

    /** @throws IrreversibleMigrationException */
    public function down(): void
    {
        throw new IrreversibleMigrationException(
            'Dropping the social registration key would let a retried social registration create a duplicate account.',
        );
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

}
