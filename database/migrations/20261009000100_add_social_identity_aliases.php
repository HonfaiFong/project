<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class AddSocialIdentityAliases extends AbstractMigration
{
    public function up(): void
    {
        $prefix = $this->getAdapter()->getOption('schema_prefix') ?? '';
        if (!is_string($prefix) || preg_match('/\A[a-zA-Z0-9_]*\z/', $prefix) !== 1
            || strlen($prefix . 'auth_db') > 64) {
            throw new RuntimeException('Invalid schema_prefix for auth_db');
        }
        $schema = $prefix . 'auth_db';
        $table = $this->table($schema . '.social_identity_aliases', [
            'id' => false,
            'primary_key' => ['provider', 'provider_app_id', 'openid'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_bin',
        ]);
        if ($table->exists()) {
            return;
        }

        $table->addColumn('provider', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('provider_app_id', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('openid', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('social_identity_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addIndex(['social_identity_id'], ['name' => 'idx_social_alias_identity'])
            ->addForeignKey('social_identity_id', $schema . '.social_identities', 'id')
            ->create();
    }

    /** @throws IrreversibleMigrationException */
    public function down(): void
    {
        throw new IrreversibleMigrationException('Removing identity aliases could allow disabled identities to register again');
    }
}
