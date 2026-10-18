<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class PreserveRefreshRevocationPrecision extends AbstractMigration
{
    public function up(): void
    {
        $prefix = $this->getAdapter()->getOption('schema_prefix') ?? '';
        if (!is_string($prefix) || preg_match('/\A[a-zA-Z0-9_]*\z/', $prefix) !== 1
            || strlen($prefix . 'auth_db') > 64) {
            throw new RuntimeException('Invalid schema_prefix for auth_db');
        }

        $this->execute('ALTER TABLE `' . $prefix . 'auth_db`.`refresh_tokens` '
            . 'MODIFY COLUMN `revoked_at` DATETIME(6) NULL');
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Reducing revocation precision can reactivate revoked access tokens');
    }
}
