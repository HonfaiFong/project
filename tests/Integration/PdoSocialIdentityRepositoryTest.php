<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contracts\Errors\ErrorCode;
use Packages\IdGenerator\Snowflake;
use PDO;
use RuntimeException;
use Services\AuthService\Domain\Auth\ExternalIdentity;
use Services\AuthService\Domain\Auth\SocialProvider;
use Services\AuthService\Infrastructure\Repository\PdoSocialIdentityRepository;
use Tests\TestCase;
use Throwable;

final class PdoSocialIdentityRepositoryTest extends TestCase
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

        $this->pdo->exec('DROP TABLE IF EXISTS social_identities');
        $migrationPath = __DIR__ . '/../../infra/docker/mysql/migrations/0005_social_identities.sql';
        $migration = file_get_contents($migrationPath);
        if ($migration === false) {
            throw new RuntimeException('Unable to read the social identities migration');
        }
        $createTableOffset = strpos($migration, 'CREATE TABLE');
        if ($createTableOffset === false) {
            throw new RuntimeException('Social identities migration has no CREATE TABLE statement');
        }
        $this->pdo->exec(substr($migration, $createTableOffset));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS social_identities');
        }
    }

    public function testConcurrentReserveReturnsOneIdentityRow(): void
    {
        $identity = $this->identity('concurrent-openid', 'concurrent-unionid');
        $prefix = tempnam(sys_get_temp_dir(), 'social-reserve-');
        if ($prefix === false) {
            $this->fail('Unable to create a concurrency coordination path');
        }
        unlink($prefix);

        $readyPaths = [$prefix . '-1-ready', $prefix . '-2-ready'];
        $resultPaths = [$prefix . '-1-result', $prefix . '-2-result'];
        $startPath = $prefix . '-start';
        /** @var array<int, array{process: resource, pipes: array<int, resource>}> $workers */
        $workers = [];
        $this->pdo->exec(
            'CREATE TRIGGER social_identity_reserve_delay
             BEFORE INSERT ON social_identities
             FOR EACH ROW
             BEGIN
                 DO SLEEP(0.1);
             END'
        );

        try {
            $workerScript = __DIR__ . '/fixtures/PdoSocialIdentityReserveWorker.php';
            foreach ([0, 1] as $index) {
                $pipes = [];
                $process = proc_open(
                    [
                        PHP_BINARY,
                        $workerScript,
                        $readyPaths[$index],
                        $resultPaths[$index],
                        $startPath,
                        $identity->openId,
                        $identity->unionId ?? '',
                        $identity->providerAppId,
                        (string)($index + 1),
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
                    throw new RuntimeException('Unable to start concurrent reserve worker');
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
            $this->waitForReserveWorkers($workers, 5.0);

            $results = array_map(
                static fn(string $path): string => trim((string)file_get_contents($path)),
                $resultPaths,
            );
            foreach ($results as $result) {
                $this->assertStringStartsWith('ok|', $result, implode(', ', $results));
            }

            [$firstStatus, $firstId] = explode('|', $results[0], 2);
            [, $secondId] = explode('|', $results[1], 2);
            $this->assertSame('ok', $firstStatus);
            $this->assertSame($firstId, $secondId);
            $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM social_identities')->fetchColumn());
        } finally {
            // Release workers even when the ready barrier fails, then terminate
            // and reap them before the fixture table is torn down.
            file_put_contents($startPath, 'start');
            $this->stopReserveWorkers($workers);
            foreach ($readyPaths as $path) {
                @unlink($path);
            }
            foreach ($resultPaths as $path) {
                @unlink($path);
            }
            @unlink($startPath);
            @unlink($prefix);
            $this->pdo->exec('DROP TRIGGER IF EXISTS social_identity_reserve_delay');
        }
    }

    public function testSecondIdentityForSameUserAndProviderReturnsStableConflict(): void
    {
        $repository = $this->repository();
        $first = $repository->reserve($this->identity('bound-openid-1'));
        $second = $repository->reserve($this->identity('bound-openid-2'));

        $repository->bindToUser($first->id, '1001');

        try {
            $repository->bindToUser($second->id, '1001');
            $this->fail('Expected provider uniqueness conflict');
        } catch (RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_PROVIDER_ALREADY_BOUND->value, $e->getCode());
        }
    }

    public function testOpenIdOnlyRowIsPromotedToUnionIdInPlace(): void
    {
        $repository = $this->repository();
        $openId = 'promotion-openid';
        $unionId = 'promotion-unionid';
        $openOnly = $this->identity($openId);
        $reserved = $repository->reserve($openOnly);

        $promoted = $repository->findByExternalIdentity($this->identity($openId, $unionId));

        $this->assertNotNull($promoted);
        $this->assertSame($reserved->id, $promoted->id);
        $this->assertSame('unionid', $promoted->subjectType);
        $this->assertSame($unionId, $promoted->subjectId);
        $this->assertSame($unionId, $promoted->unionId);
        $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM social_identities')->fetchColumn());
        $this->assertSame(
            $unionId,
            $this->pdo->query('SELECT subject_id FROM social_identities')->fetchColumn(),
        );
    }

    public function testUnionActiveAndOpenIdPendingKeepTheActiveUnionRow(): void
    {
        $repository = $this->repository();
        $this->insertIdentity(
            id: '4101',
            providerAppId: 'wechat-other-app',
            subjectType: 'unionid',
            subjectId: 'active-unionid',
            openId: 'active-other-openid',
            unionId: 'active-unionid',
            userId: '4100',
            status: 1,
        );
        $this->insertIdentity(
            id: '4102',
            providerAppId: 'wechat-main-app',
            subjectType: 'openid',
            subjectId: 'active-openid',
            openId: 'active-openid',
            unionId: null,
            userId: null,
            status: 0,
        );

        $canonical = $repository->findByExternalIdentity(
            $this->identity('active-openid', 'active-unionid', 'wechat-main-app'),
        );

        $this->assertNotNull($canonical);
        $this->assertSame('4101', $canonical->id);
        $this->assertSame('4100', $canonical->userId);
        $this->assertSame(1, $canonical->status);
        $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM social_identities')->fetchColumn());
    }

    public function testUnionPendingAndOpenIdActiveTransferTheUserToTheUnionRow(): void
    {
        $repository = $this->repository();
        $this->insertIdentity(
            id: '4201',
            providerAppId: 'wechat-other-app',
            subjectType: 'unionid',
            subjectId: 'pending-unionid',
            openId: 'pending-other-openid',
            unionId: 'pending-unionid',
            userId: null,
            status: 0,
        );
        $this->insertIdentity(
            id: '4202',
            providerAppId: 'wechat-main-app',
            subjectType: 'openid',
            subjectId: 'pending-openid',
            openId: 'pending-openid',
            unionId: null,
            userId: '4200',
            status: 1,
        );

        $canonical = $repository->findByExternalIdentity(
            $this->identity('pending-openid', 'pending-unionid', 'wechat-main-app'),
        );

        $this->assertNotNull($canonical);
        $this->assertSame('4201', $canonical->id);
        $this->assertSame('4200', $canonical->userId);
        $this->assertSame(1, $canonical->status);
        $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM social_identities')->fetchColumn());
    }

    public function testBothPendingRowsMergeToTheUnionRow(): void
    {
        $repository = $this->repository();
        $this->insertIdentity(
            id: '4301',
            providerAppId: 'wechat-other-app',
            subjectType: 'unionid',
            subjectId: 'both-pending-unionid',
            openId: 'both-pending-other-openid',
            unionId: 'both-pending-unionid',
            userId: null,
            status: 0,
        );
        $this->insertIdentity(
            id: '4302',
            providerAppId: 'wechat-main-app',
            subjectType: 'openid',
            subjectId: 'both-pending-openid',
            openId: 'both-pending-openid',
            unionId: null,
            userId: null,
            status: 0,
        );

        $canonical = $repository->findByExternalIdentity(
            $this->identity('both-pending-openid', 'both-pending-unionid', 'wechat-main-app'),
        );

        $this->assertNotNull($canonical);
        $this->assertSame('4301', $canonical->id);
        $this->assertNull($canonical->userId);
        $this->assertSame(0, $canonical->status);
        $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM social_identities')->fetchColumn());
    }

    public function testSameUserLegacyUnionAndOpenIdRowsAreMergedAndOpenIdRowRemoved(): void
    {
        $repository = $this->repository();
        // Legacy duplicate rows could exist before uniq_user_provider was added.
        // Drop only that invariant while preparing the fixture, then restore it
        // after reconciliation proves that one canonical row remains.
        $this->pdo->exec('ALTER TABLE social_identities DROP INDEX uniq_user_provider');
        $this->insertIdentity(
            id: '2001',
            providerAppId: 'wechat-other-app',
            subjectType: 'unionid',
            subjectId: 'merge-unionid',
            openId: 'merge-other-openid',
            unionId: 'merge-unionid',
            userId: '1001',
            status: 1,
        );
        $this->insertIdentity(
            id: '2002',
            providerAppId: 'wechat-main-app',
            subjectType: 'openid',
            subjectId: 'merge-openid',
            openId: 'merge-openid',
            unionId: null,
            userId: '1001',
            status: 1,
        );
        $canonical = $repository->findByExternalIdentity(
            $this->identity('merge-openid', 'merge-unionid', 'wechat-main-app'),
        );

        $this->assertNotNull($canonical);
        $this->assertSame('2001', $canonical->id);
        $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM social_identities')->fetchColumn());
        $this->assertSame('2001', (string)$this->pdo->query('SELECT id FROM social_identities')->fetchColumn());
        $this->pdo->exec('ALTER TABLE social_identities ADD UNIQUE KEY uniq_user_provider (user_id, provider)');
    }

    public function testDifferentUsersForUnionAndOpenIdRowsReturnConflictAndRemainUnchanged(): void
    {
        $repository = $this->repository();
        $this->insertIdentity(
            id: '3001',
            providerAppId: 'wechat-other-app',
            subjectType: 'unionid',
            subjectId: 'conflict-unionid',
            openId: 'conflict-other-openid',
            unionId: 'conflict-unionid',
            userId: '1001',
            status: 1,
        );
        $this->insertIdentity(
            id: '3002',
            providerAppId: 'wechat-main-app',
            subjectType: 'openid',
            subjectId: 'conflict-openid',
            openId: 'conflict-openid',
            unionId: null,
            userId: '1002',
            status: 1,
        );

        try {
            $repository->findByExternalIdentity(
                $this->identity('conflict-openid', 'conflict-unionid', 'wechat-main-app'),
            );
            $this->fail('Expected a unionid/openid identity conflict');
        } catch (RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->value, $e->getCode());
        }

        $this->assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM social_identities')->fetchColumn());
    }

    public function testDisabledIdentityStateWinsWhenRowsAreMerged(): void
    {
        $repository = $this->repository();
        $this->pdo->exec('ALTER TABLE social_identities DROP INDEX uniq_user_provider');

        $this->insertIdentity(
            id: '5101',
            providerAppId: 'wechat-other-app',
            subjectType: 'unionid',
            subjectId: 'disabled-unionid',
            openId: 'disabled-other-openid',
            unionId: 'disabled-unionid',
            userId: '5100',
            status: 1,
        );
        $this->insertIdentity(
            id: '5102',
            providerAppId: 'wechat-main-app',
            subjectType: 'openid',
            subjectId: 'disabled-openid',
            openId: 'disabled-openid',
            unionId: null,
            userId: '5100',
            status: 2,
        );

        $canonical = $repository->findByExternalIdentity(
            $this->identity('disabled-openid', 'disabled-unionid', 'wechat-main-app'),
        );

        $this->assertNotNull($canonical);
        $this->assertSame('5100', $canonical->userId);
        $this->assertSame(2, $canonical->status);
        $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM social_identities')->fetchColumn());
    }

    private function repository(): PdoSocialIdentityRepository
    {
        return new PdoSocialIdentityRepository($this->pdo, new Snowflake(random_int(1, 1023)));
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
                PDO::ATTR_TIMEOUT => 2,
            ],
        );
    }

    private function identity(
        string $openId,
        ?string $unionId = null,
        string $providerAppId = 'wechat-main-app',
    ): ExternalIdentity {
        return new ExternalIdentity(
            provider: SocialProvider::WECHAT,
            providerAppId: $providerAppId,
            identityNamespace: 'wechat-main',
            openId: $openId,
            unionId: $unionId,
        );
    }

    private function insertIdentity(
        string $id,
        string $providerAppId,
        string $subjectType,
        string $subjectId,
        string $openId,
        ?string $unionId,
        ?string $userId,
        int $status,
    ): void {
        $now = gmdate('Y-m-d H:i:s.v');
        $stmt = $this->pdo->prepare(
            'INSERT INTO social_identities
             (id, user_id, provider, provider_app_id, identity_namespace, subject_type, subject_id,
              openid, unionid, status, bound_at, last_login_at, created_at, updated_at)
             VALUES
             (:id, :user_id, :provider, :provider_app_id, :identity_namespace, :subject_type, :subject_id,
              :openid, :unionid, :status, :bound_at, NULL, :created_at, :updated_at)'
        );
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
            'provider' => SocialProvider::WECHAT->value,
            'provider_app_id' => $providerAppId,
            'identity_namespace' => 'wechat-main',
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'openid' => $openId,
            'unionid' => $unionId,
            'status' => $status,
            'bound_at' => $userId === null ? null : $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<int, array{process: resource, pipes: array<int, resource>}> $workers
     */
    private function waitForReserveWorkers(array &$workers, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $statuses = [];
        while (microtime(true) < $deadline) {
            $allExited = true;
            foreach ($workers as $index => $worker) {
                // PHP 8.2 only reports the exit code on the first status read.
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
                throw new RuntimeException('Concurrent reserve workers exceeded the timeout');
            }
            if ($status['exitcode'] !== 0) {
                throw new RuntimeException('Concurrent reserve worker exited unexpectedly');
            }
            $this->closeReserveWorker($worker);
            unset($workers[$index]);
        }
    }

    /**
     * @param array<int, array{process: resource, pipes: array<int, resource>}> $workers
     */
    private function stopReserveWorkers(array &$workers): void
    {
        foreach ($workers as $worker) {
            $status = proc_get_status($worker['process']);
            if ($status['running']) {
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
            $this->closeReserveWorker($worker);
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
    private function closeReserveWorker(array $worker): void
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
            if (array_reduce($paths, static fn(bool $ready, string $path): bool => $ready && is_file($path), true)) {
                return;
            }
            usleep(10000);
        }

        $this->fail('Timed out waiting for concurrent reserve workers');
    }
}
