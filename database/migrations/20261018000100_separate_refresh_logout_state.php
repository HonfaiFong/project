<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class SeparateRefreshLogoutState extends AbstractMigration
{
    public function up(): void
    {
        $prefix = $this->getAdapter()->getOption('schema_prefix') ?? '';
        if (!is_string($prefix) || preg_match('/\A[a-zA-Z0-9_]*\z/', $prefix) !== 1
            || strlen($prefix . 'auth_db') > 64) {
            throw new RuntimeException('Invalid schema_prefix for auth_db');
        }

        $table = $this->table($prefix . 'auth_db.refresh_tokens');
        if (!$table->hasColumn('logged_out_at')) {
            $table->addColumn('logged_out_at', 'datetime', ['null' => true, 'precision' => 6])->update();
        }
        // Historical revoked_at cannot distinguish rotation from logout. Do not authorize recovery from it.
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Removing logout state loses the boundary for safe retry');
    }
}
