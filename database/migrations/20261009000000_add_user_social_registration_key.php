<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;
use Phinx\Db\Table\Column;

/**
 * Persists the caller-owned registration key used by
 * `user.v1.UserService/FindOrCreateBySocialIdentity` on the user row it created.
 *
 * The column is nullable and unique: MySQL allows any number of NULLs in a
 * unique index, so existing phone/email users keep working untouched, while a
 * repeated or retried social registration can never create a second account
 * for the same key. The unique key fails the competing insert and the service
 * re-reads the winning row instead of guessing.
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
