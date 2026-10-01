<?php

declare(strict_types=1);

namespace Tests\Integration;

use Packages\Phone\PhoneNormalizer;
use PDO;
use RuntimeException;
use Services\UserService\Infrastructure\Migration\UserPhoneMigration;
use Tests\TestCase;
use Throwable;

final class UserPhoneMigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $database = getenv('SOCIAL_TEST_MYSQL_DATABASE');
        if ($database === false || $database === '') {
            $this->markTestSkipped('SOCIAL_TEST_MYSQL_DATABASE is not configured');
        }
        if (!str_starts_with($database, 'social_test_')) {
            throw new RuntimeException('SOCIAL_TEST_MYSQL_DATABASE must use the social_test_ prefix');
        }

        try {
            $this->pdo = $this->connectTestDatabase($database);
        } catch (Throwable $e) {
            $this->markTestSkipped('MySQL integration service is not available: ' . $e->getMessage());
        }

        $this->pdo->exec('DROP TABLE IF EXISTS users');
        $this->pdo->exec(
            'CREATE TABLE users (
                id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                phone VARCHAR(32) NULL UNIQUE,
                updated_at DATETIME(3) NOT NULL
            ) ENGINE=InnoDB'
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS users');
        }
    }

    public function testCollisionIsRejectedWithoutChangingUsers(): void
    {
        $this->insertUser(1, '13800000000');
        $this->insertUser(2, '+8613800000000');

        try {
            $this->migration()->apply($this->pdo);
            $this->fail('Expected a normalized phone collision to abort the migration');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('normalized phone collision', $e->getMessage());
            $this->assertStringNotContainsString('13800000000', $e->getMessage());
        }

        $this->assertSame('13800000000', $this->phoneFor(1));
        $this->assertSame('+8613800000000', $this->phoneFor(2));
    }

    public function testCleanApplyNormalizesLegacyPhones(): void
    {
        $this->insertUser(1, '13800000000');
        $this->insertUser(2, '+8613900000000');

        $result = $this->migration()->apply($this->pdo);

        $this->assertSame(['updated' => 1], $result);
        $this->assertSame('+8613800000000', $this->phoneFor(1));
        $this->assertSame('+8613900000000', $this->phoneFor(2));
    }

    public function testMoreThanOneBatchIsScannedAppliedAndReportContainsNoPhoneValues(): void
    {
        for ($id = 1; $id <= 501; $id++) {
            $this->insertUser($id, '138' . str_pad((string)($id - 1), 8, '0', STR_PAD_LEFT));
        }

        $migration = new UserPhoneMigration(new PhoneNormalizer(), 500);
        $report = $migration->preflight($this->pdo);

        $this->assertSame(501, $report['total_count']);
        $this->assertSame(501, $report['change_count']);
        $this->assertSame(0, $report['invalid_count']);
        $this->assertSame(0, $report['collision_count']);
        $this->assertCount(20, $report['change_user_ids']);
        $this->assertStringNotContainsString('13800000000', json_encode($report, JSON_THROW_ON_ERROR));

        $result = $migration->apply($this->pdo);

        $this->assertSame(['updated' => 501], $result);
        $this->assertSame(501, (int)$this->pdo->query(
            "SELECT COUNT(*) FROM users WHERE phone LIKE '+86138%'"
        )->fetchColumn());
        $this->assertSame('+8613800000000', $this->phoneFor(1));
        $this->assertSame('+8613800000500', $this->phoneFor(501));
    }

    private function migration(): UserPhoneMigration
    {
        return new UserPhoneMigration(new PhoneNormalizer());
    }

    private function insertUser(int $id, string $phone): void
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

    private function phoneFor(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT phone FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return (string)$stmt->fetchColumn();
    }

    private function connectTestDatabase(string $database): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                getenv('SOCIAL_TEST_MYSQL_HOST') ?: '127.0.0.1',
                (int)(getenv('SOCIAL_TEST_MYSQL_PORT') ?: 3306),
                $database,
            ),
            getenv('SOCIAL_TEST_MYSQL_USER') ?: 'root',
            getenv('SOCIAL_TEST_MYSQL_PASSWORD') ?: '',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT             => 2,
            ],
        );
    }
}
