<?php

declare(strict_types=1);

namespace Tests\Unit;

use Packages\Phone\PhoneNormalizer;
use PDO;
use RuntimeException;
use Services\UserService\Infrastructure\Migration\UserPhoneMigration;
use Tests\TestCase;

final class UserPhoneMigrationTest extends TestCase
{
    private PDO $pdo;
    private UserPhoneMigration $migration;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec(
            'CREATE TABLE users (
                id TEXT PRIMARY KEY,
                phone TEXT UNIQUE,
                updated_at TEXT NOT NULL
            )'
        );
        $this->migration = new UserPhoneMigration(new PhoneNormalizer());
    }

    public function testPreflightReportsLegacyPhoneChangeWithoutWriting(): void
    {
        $this->insertUser('1', '13800000000');

        $plan = $this->migration->preflight($this->pdo);

        $this->assertSame([], $plan['invalid']);
        $this->assertSame([], $plan['collisions']);
        $this->assertSame([
            [
                'id'               => '1',
                'phone'            => '13800000000',
                'normalized_phone' => '+8613800000000',
            ],
        ], $plan['changes']);
        $this->assertSame('13800000000', $this->phoneFor('1'));
    }

    public function testApplyNormalizesPhonesInATransaction(): void
    {
        $this->insertUser('1', '13800000000');
        $this->insertUser('2', '+8613900000000');

        $result = $this->migration->apply($this->pdo);

        $this->assertSame(['updated' => 1], $result);
        $this->assertSame('+8613800000000', $this->phoneFor('1'));
        $this->assertSame('+8613900000000', $this->phoneFor('2'));
    }

    public function testApplyFailsBeforeWritingWhenNormalizationCollides(): void
    {
        $this->insertUser('1', '13800000000');
        $this->insertUser('2', '+8613800000000');

        try {
            $this->migration->apply($this->pdo);
            $this->fail('Expected a normalized phone collision to abort the migration');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('normalized phone collision', $e->getMessage());
        }

        $this->assertSame('13800000000', $this->phoneFor('1'));
        $this->assertSame('+8613800000000', $this->phoneFor('2'));
    }

    public function testApplyFailsBeforeWritingWhenPhoneIsInvalid(): void
    {
        $this->insertUser('1', 'not-a-phone');
        $this->insertUser('2', '13800000000');

        try {
            $this->migration->apply($this->pdo);
            $this->fail('Expected invalid phone to abort the migration');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('invalid phone', $e->getMessage());
        }

        $this->assertSame('not-a-phone', $this->phoneFor('1'));
        $this->assertSame('13800000000', $this->phoneFor('2'));
    }

    private function insertUser(string $id, string $phone): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, phone, updated_at) VALUES (:id, :phone, :updated_at)'
        );
        $stmt->execute([
            'id'         => $id,
            'phone'      => $phone,
            'updated_at' => '2026-01-01 00:00:00.000',
        ]);
    }

    private function phoneFor(string $id): string
    {
        $stmt = $this->pdo->prepare('SELECT phone FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return (string)$stmt->fetchColumn();
    }
}
