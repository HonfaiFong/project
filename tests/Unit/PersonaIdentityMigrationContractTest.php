<?php

declare(strict_types=1);

namespace Tests\Unit;

use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

require_once __DIR__ . '/../../database/migrations/20261010000100_add_internal_alias_identity.php';
require_once __DIR__ . '/../../database/migrations/20261010000200_add_anonymous_author_snapshots.php';

final class PersonaIdentityMigrationContractTest extends TestCase
{
    public function testCanonicalCheckDefinitionIsAccepted(): void
    {
        $this->assertTrue($this->isExpectedCheck([
            'check_clause' => '(`is_internal_alias` IN (0, 1) AND (`is_internal_alias` = 0 OR `type` = 2))',
            'enforced' => 'YES',
        ]));
    }

    public function testEquivalentLookingWeakerCheckDefinitionIsRejected(): void
    {
        $this->assertFalse($this->isExpectedCheck([
            // Parentheses change the contract: the type guard is no longer
            // required for every internal alias row.
            'check_clause' => '((`is_internal_alias` IN (0, 1) AND `is_internal_alias` = 0) OR `type` = 2)',
            'enforced' => 'YES',
        ]));
        $this->assertFalse($this->isExpectedCheck([
            'check_clause' => '(`is_internal_alias` IN (0, 1) AND (`is_internal_alias` = 0 OR `type` = 2))',
            'enforced' => 'NO',
        ]));
    }

    public function testSnapshotMigrationRequiresUnsignedModeAndScopedColumns(): void
    {
        $reflection = new ReflectionClass(\AddAnonymousAuthorSnapshots::class);
        /** @var array<string, array<string, array<string, string>>> $tables */
        $tables = $reflection->getConstant('TABLES');

        $this->assertArrayHasKey('author_scope_type', $tables['content_db']['comments']);
        $this->assertArrayHasKey('author_scope_id', $tables['content_db']['comments']);
        $this->assertArrayHasKey('scope_type', $tables['chat_db']['conversation_members']);
        $this->assertArrayHasKey('sender_scope_type', $tables['message_db']['messages']);

        $migration = $reflection->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(\AddAnonymousAuthorSnapshots::class, 'isExpectedSnapshotColumn');
        $expected = [
            'data_type' => 'tinyint',
            'column_type' => 'tinyint unsigned',
            'is_nullable' => 'NO',
            'column_default' => '0',
        ];
        $this->assertTrue((bool)$method->invoke($migration, 'identity_mode', $expected));
        $expected['column_type'] = 'tinyint(1)';
        $this->assertFalse((bool)$method->invoke($migration, 'identity_mode', $expected));
    }

    /** @param array{check_clause: string, enforced: string} $check */
    private function isExpectedCheck(array $check): bool
    {
        $migration = (new ReflectionClass(\AddInternalAliasIdentity::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(\AddInternalAliasIdentity::class, 'isExpectedCheck');

        return (bool)$method->invoke($migration, $check);
    }
}
