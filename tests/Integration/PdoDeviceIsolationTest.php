<?php

declare(strict_types=1);

namespace Tests\Integration;

use Packages\IdGenerator\Snowflake;
use PDO;
use RuntimeException;
use Services\AuthService\Infrastructure\Repository\PdoAuthRepository;
use Tests\TestCase;
use Throwable;

final class PdoDeviceIsolationTest extends TestCase
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

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS refresh_tokens');
        $this->pdo->exec('DROP TABLE IF EXISTS devices');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->createLegacySchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TRIGGER IF EXISTS device_insert_delay');
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $this->pdo->exec('DROP TABLE IF EXISTS refresh_tokens');
            $this->pdo->exec('DROP TABLE IF EXISTS devices');
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    public function testMigrationRevokesInconsistentRefreshTokensAndIsIdempotent(): void
    {
        $this->insertDevice('1001', '2001', 'shared-device');
        $this->insertDevice('1002', '2002', 'other-device');
        $this->insertRefreshToken('3001', '2001', '1001', str_repeat('a', 64), null);
        $this->insertRefreshToken('3002', '2999', '1001', str_repeat('b', 64), null);
        $this->insertRefreshToken('3003', '2998', '9999', str_repeat('c', 64), null);
        $this->insertRefreshToken('3004', '2997', '1001', str_repeat('d', 64), '2026-01-01 00:00:00.000');
        $this->insertDevice('1003', '1001', 'migrated-user-device');
        $this->insertDevice('1004', '1002', 'migrated-device-owner');
        $this->insertRefreshToken('3005', '1001', '1004', str_repeat('e', 64), null);
        $this->insertRefreshToken('3006', '1002', '1004', str_repeat('f', 64), null);

        $this->applyDeviceMigration();

        $this->assertDeviceIndexesAreUserScoped();
        $this->assertNull($this->refreshTokenRevokedAt(str_repeat('a', 64)));
        $this->assertNotNull($this->refreshTokenRevokedAt(str_repeat('b', 64)));
        $this->assertNotNull($this->refreshTokenRevokedAt(str_repeat('c', 64)));
        $this->assertSame('2026-01-01 00:00:00.000', $this->refreshTokenRevokedAt(str_repeat('d', 64)));
        $this->assertNotNull($this->refreshTokenRevokedAt(str_repeat('e', 64)));
        $this->assertNull($this->refreshTokenRevokedAt(str_repeat('f', 64)));

        $repository = $this->repository();
        $this->assertNotNull($repository->findRefreshToken(str_repeat('a', 64)));
        $this->assertNull($repository->findRefreshToken(str_repeat('b', 64)));
        $this->assertNull($repository->findRefreshToken(str_repeat('c', 64)));
        $this->assertNull($repository->findRefreshToken(str_repeat('e', 64)));
        $this->assertNotNull($repository->findRefreshToken(str_repeat('f', 64)));
        $this->assertNull(
            $repository->rotateRefreshToken(str_repeat('e', 64), str_repeat('g', 64), '2099-01-01 00:00:00.000')
        );

        $snapshot = $this->refreshTokenRows();
        $this->applyDeviceMigration();
        $this->assertSame($snapshot, $this->refreshTokenRows());
        $this->assertDeviceIndexesAreUserScoped();
    }

    public function testSameUuidAndSessionOperationsRemainIndependentAcrossUsers(): void
    {
        $this->applyDeviceMigration();
        $repository = $this->repository();

        $first = $repository->findOrCreateDevice('2001', 'shared-device', 'ios', '1.0.0', null, null);
        $second = $repository->findOrCreateDevice('2002', 'shared-device', 'android', '2.0.0', null, null);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM devices')->fetchColumn());
        $this->assertTrue($repository->deviceBelongsToUser('2001', $first->id));
        $this->assertFalse($repository->deviceBelongsToUser('2002', $first->id));
        $this->assertTrue($repository->deviceBelongsToUser('2002', $second->id));

        $firstHash = str_repeat('e', 64);
        $secondHash = str_repeat('f', 64);
        $repository->createRefreshToken('2001', $first->id, $firstHash, '2099-01-01 00:00:00.000');
        $repository->createRefreshToken('2002', $second->id, $secondHash, '2099-01-01 00:00:00.000');

        $rotatedHash = str_repeat('1', 64);
        $this->assertNotNull(
            $repository->rotateRefreshToken($firstHash, $rotatedHash, '2099-01-01 00:00:00.000')
        );
        $rotated = $repository->findRefreshToken($firstHash);
        $this->assertNotNull($rotated);
        $this->assertFalse($rotated->isValid());
        $replacement = $repository->findRefreshToken($rotatedHash);
        $this->assertNotNull($replacement);
        $this->assertTrue($replacement->isValid());
        $secondToken = $repository->findRefreshToken($secondHash);
        $this->assertNotNull($secondToken);
        $this->assertTrue($secondToken->isValid());

        $this->assertTrue($repository->revokeRefreshToken($rotatedHash));
        $revoked = $repository->findRefreshToken($rotatedHash);
        $this->assertNotNull($revoked);
        $this->assertFalse($revoked->isValid());
        $secondToken = $repository->findRefreshToken($secondHash);
        $this->assertNotNull($secondToken);
        $this->assertTrue($secondToken->isValid());
    }

    public function testConcurrentSameUserCreationReusesOneDeviceRow(): void
    {
        $this->applyDeviceMigration();
        $this->pdo->exec(
            'CREATE TRIGGER device_insert_delay
             BEFORE INSERT ON devices
             FOR EACH ROW
             BEGIN
                 DO SLEEP(0.1);
             END'
        );

        $prefix = tempnam(sys_get_temp_dir(), 'device-reserve-');
        if ($prefix === false) {
            $this->fail('Unable to create a concurrency coordination path');
        }
        unlink($prefix);

        $readyPaths = [$prefix . '-1-ready', $prefix . '-2-ready'];
        $resultPaths = [$prefix . '-1-result', $prefix . '-2-result'];
        $startPath = $prefix . '-start';
        /** @var array<int, array{process: resource, pipes: array<int, resource>}> $workers */
        $workers = [];

        try {
            $workerScript = __DIR__ . '/fixtures/PdoDeviceReserveWorker.php';
            foreach ([1, 2] as $index) {
                $pipes = [];
                $process = proc_open(
                    [
                        PHP_BINARY,
                        $workerScript,
                        $readyPaths[$index - 1],
                        $resultPaths[$index - 1],
                        $startPath,
                        '2001',
                        'concurrent-device',
                        (string)$index,
                    ],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname(__DIR__, 2),
                    null,
                    ['bypass_shell' => true],
                );
                if (!is_resource($process)) {
                    throw new RuntimeException('Unable to start concurrent device worker');
                }
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $workers[] = [
                    'process' => $process,
                    'pipes' => $pipes,
                ];
            }

            $this->waitForPaths($readyPaths);
            file_put_contents($startPath, 'start');
            $this->waitForWorkers($workers, 5.0);

            $results = array_map(
                static fn(string $path): string => trim((string)file_get_contents($path)),
                $resultPaths,
            );
            foreach ($results as $result) {
                $this->assertStringStartsWith('ok|', $result, implode(', ', $results));
            }

            [, $firstId] = explode('|', $results[0], 2);
            [, $secondId] = explode('|', $results[1], 2);
            $this->assertSame($firstId, $secondId);
            $this->assertSame(1, (int)$this->pdo->query(
                "SELECT COUNT(*) FROM devices WHERE user_id = 2001 AND device_uuid = 'concurrent-device'"
            )->fetchColumn());
        } finally {
            file_put_contents($startPath, 'start');
            $this->stopWorkers($workers);
            foreach ($readyPaths as $path) {
                @unlink($path);
            }
            foreach ($resultPaths as $path) {
                @unlink($path);
            }
            @unlink($startPath);
            @unlink($prefix);
            $this->pdo->exec('DROP TRIGGER IF EXISTS device_insert_delay');
        }
    }

    private function repository(): PdoAuthRepository
    {
        return new PdoAuthRepository($this->pdo, new Snowflake(random_int(1, 1023)));
    }

    private function createLegacySchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE devices (
                id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                device_uuid VARCHAR(64) NOT NULL,
                platform VARCHAR(16) NOT NULL,
                app_version VARCHAR(32) NOT NULL,
                push_token VARCHAR(255) NULL,
                last_ip VARBINARY(16) NULL,
                last_active_at DATETIME(3) NOT NULL,
                created_at DATETIME(3) NOT NULL,
                updated_at DATETIME(3) NOT NULL,
                UNIQUE KEY uniq_device_uuid (device_uuid),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->pdo->exec(
            'CREATE TABLE refresh_tokens (
                id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                device_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME(3) NOT NULL,
                revoked_at DATETIME(3) NULL,
                created_at DATETIME(3) NOT NULL,
                UNIQUE KEY uniq_token_hash (token_hash),
                INDEX idx_user_device (user_id, device_id),
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function applyDeviceMigration(): void
    {
        $path = __DIR__ . '/../../infra/docker/mysql/migrations/0006_device_user_scope.sql';
        $migration = file_get_contents($path);
        if ($migration === false) {
            throw new RuntimeException('Unable to read device migration');
        }

        $migration = preg_replace('/^USE `auth_db`;\s*/m', '', $migration, 1);
        if ($migration === null) {
            throw new RuntimeException('Unable to prepare device migration for test database');
        }

        $this->pdo->exec($migration);
    }

    private function insertDevice(string $id, string $userId, string $deviceUuid): void
    {
        $now = gmdate('Y-m-d H:i:s.v');
        $stmt = $this->pdo->prepare(
            'INSERT INTO devices
             (id, user_id, device_uuid, platform, app_version, push_token, last_ip,
              last_active_at, created_at, updated_at)
             VALUES (:id, :user_id, :device_uuid, :platform, :app_version, NULL, NULL,
                     :last_active_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
            'device_uuid' => $deviceUuid,
            'platform' => 'ios',
            'app_version' => '1.0.0',
            'last_active_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertRefreshToken(
        string $id,
        string $userId,
        string $deviceId,
        string $tokenHash,
        ?string $revokedAt,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO refresh_tokens
             (id, user_id, device_id, token_hash, expires_at, revoked_at, created_at)
             VALUES (:id, :user_id, :device_id, :token_hash, :expires_at, :revoked_at, :created_at)'
        );
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'token_hash' => $tokenHash,
            'expires_at' => '2099-01-01 00:00:00.000',
            'revoked_at' => $revokedAt,
            'created_at' => gmdate('Y-m-d H:i:s.v'),
        ]);
    }

    private function refreshTokenRevokedAt(string $tokenHash): ?string
    {
        $stmt = $this->pdo->prepare('SELECT revoked_at FROM refresh_tokens WHERE token_hash = :token_hash');
        $stmt->execute(['token_hash' => $tokenHash]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string)$value;
    }

    /** @return array<int, array<string, mixed>> */
    private function refreshTokenRows(): array
    {
        return $this->pdo->query(
            'SELECT id, user_id, device_id, token_hash, expires_at, revoked_at, created_at
             FROM refresh_tokens ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assertDeviceIndexesAreUserScoped(): void
    {
        $indexes = $this->pdo->query('SHOW INDEX FROM devices')->fetchAll(PDO::FETCH_ASSOC);
        $indexNames = array_column($indexes, 'Key_name');

        $this->assertContains('uniq_user_device_uuid', $indexNames);
        $this->assertNotContains('uniq_device_uuid', $indexNames);
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
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 2,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ],
        );
    }

    /**
     * @param array<int, array{process: resource, pipes: array<int, resource>}> $workers
     */
    private function waitForWorkers(array &$workers, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $statuses = [];
        while (microtime(true) < $deadline) {
            $allExited = true;
            foreach ($workers as $index => $worker) {
                if (isset($statuses[$index]) && !$statuses[$index]['running']) {
                    continue;
                }
                $status = proc_get_status($worker['process']);
                $statuses[$index] = $status;
                $this->drainWorkerOutput($worker['pipes']);
                if ($status['running']) {
                    $allExited = false;
                }
            }
            if ($allExited) {
                break;
            }
            usleep(10000);
        }

        foreach ($workers as $index => $worker) {
            $status = $statuses[$index] ?? proc_get_status($worker['process']);
            if ($status['running']) {
                throw new RuntimeException('Concurrent device workers exceeded the timeout');
            }
            if ($status['exitcode'] !== 0) {
                throw new RuntimeException('Concurrent device worker exited unexpectedly');
            }
            $this->closeWorker($worker);
            unset($workers[$index]);
        }
    }

    /**
     * @param array<int, array{process: resource, pipes: array<int, resource>}> $workers
     */
    private function stopWorkers(array &$workers): void
    {
        foreach ($workers as $worker) {
            if (proc_get_status($worker['process'])['running']) {
                proc_terminate($worker['process']);
            }
        }

        $deadline = microtime(true) + 1.0;
        while (microtime(true) < $deadline) {
            $running = false;
            foreach ($workers as $worker) {
                $this->drainWorkerOutput($worker['pipes']);
                if (proc_get_status($worker['process'])['running']) {
                    $running = true;
                }
            }
            if (!$running) {
                break;
            }
            usleep(10000);
        }

        foreach ($workers as $worker) {
            if (proc_get_status($worker['process'])['running']) {
                proc_terminate($worker['process'], 9);
            }
            $this->closeWorker($worker);
        }
        $workers = [];
    }

    /** @param array<int, resource> $pipes */
    private function drainWorkerOutput(array $pipes): void
    {
        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                stream_get_contents($pipes[$index]);
            }
        }
    }

    /** @param array{process: resource, pipes: array<int, resource>} $worker */
    private function closeWorker(array $worker): void
    {
        $this->drainWorkerOutput($worker['pipes']);
        foreach ([1, 2] as $index) {
            if (isset($worker['pipes'][$index]) && is_resource($worker['pipes'][$index])) {
                fclose($worker['pipes'][$index]);
            }
        }
        proc_close($worker['process']);
    }

    /** @param array<string> $paths */
    private function waitForPaths(array $paths): void
    {
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            if (array_reduce(
                $paths,
                static fn(bool $ready, string $path): bool => $ready && is_file($path),
                true,
            )) {
                return;
            }
            usleep(10000);
        }

        $this->fail('Timed out waiting for concurrent device workers');
    }
}
