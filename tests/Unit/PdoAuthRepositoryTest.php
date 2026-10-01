<?php

declare(strict_types=1);

namespace Tests\Unit;

use Packages\IdGenerator\Snowflake;
use PDO;
use Services\AuthService\Infrastructure\Repository\PdoAuthRepository;
use Tests\TestCase;

final class PdoAuthRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PdoAuthRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE devices (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                device_uuid VARCHAR(64) NOT NULL,
                platform VARCHAR(16) NOT NULL,
                app_version VARCHAR(32) NOT NULL,
                push_token VARCHAR(255) NULL,
                last_ip BLOB NULL,
                last_active_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE (user_id, device_uuid)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE refresh_tokens (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                device_id INTEGER NOT NULL,
                token_hash CHAR(64) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                revoked_at DATETIME NULL,
                created_at DATETIME NOT NULL
            )'
        );
        $this->repository = new PdoAuthRepository($this->pdo, new Snowflake());
    }

    public function testSameDeviceUuidCreatesIndependentRowsPerUser(): void
    {
        $first = $this->repository->findOrCreateDevice(
            userId: '1001',
            deviceUuid: 'shared-device',
            platform: 'ios',
            appVersion: '1.0.0',
            pushToken: 'push-a',
            ip: '203.0.113.10',
        );
        $second = $this->repository->findOrCreateDevice(
            userId: '1002',
            deviceUuid: 'shared-device',
            platform: 'android',
            appVersion: '2.0.0',
            pushToken: 'push-b',
            ip: '203.0.113.11',
        );

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('1001', $first->userId);
        $this->assertSame('1002', $second->userId);
        $this->assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM devices')->fetchColumn());

        $firstAgain = $this->repository->findOrCreateDevice(
            userId: '1001',
            deviceUuid: 'shared-device',
            platform: 'ios',
            appVersion: '1.1.0',
            pushToken: null,
            ip: '203.0.113.12',
        );

        $this->assertSame($first->id, $firstAgain->id);
        $this->assertSame('1001', $firstAgain->userId);
        $this->assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM devices')->fetchColumn());
        $this->assertSame(
            '1001',
            (string)$this->pdo->query('SELECT user_id FROM devices WHERE id = ' . (int)$first->id)->fetchColumn(),
        );
        $this->assertSame(
            '1002',
            (string)$this->pdo->query('SELECT user_id FROM devices WHERE id = ' . (int)$second->id)->fetchColumn(),
        );

        $this->assertTrue($this->repository->deviceBelongsToUser('1001', $first->id));
        $this->assertFalse($this->repository->deviceBelongsToUser('1002', $first->id));
        $this->assertTrue($this->repository->deviceBelongsToUser('1002', $second->id));
    }

    public function testMismatchedRefreshTokenIsNotUsableForAnotherDeviceOwner(): void
    {
        $device = $this->repository->findOrCreateDevice(
            userId: '1001',
            deviceUuid: 'device-a',
            platform: 'ios',
            appVersion: '1.0.0',
            pushToken: null,
            ip: null,
        );

        $this->repository->createRefreshToken(
            userId: '1002',
            deviceId: $device->id,
            tokenHash: str_repeat('a', 64),
            expiresAt: '2099-01-01 00:00:00.000',
        );

        $this->assertNull($this->repository->findRefreshToken(str_repeat('a', 64)));
    }
}
