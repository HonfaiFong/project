<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contracts\Errors\ErrorCode;
use Illuminate\Database\Schema\Blueprint;
use Packages\Database\EloquentConnection;
use Packages\IdGenerator\Snowflake;
use PDO;
use Random\RandomException;
use RuntimeException;
use Services\AuthService\Domain\Auth\ExternalIdentity;
use Services\AuthService\Domain\Auth\SocialProvider;
use Services\AuthService\Infrastructure\Model\SocialIdentity as SocialIdentityModel;
use Services\AuthService\Infrastructure\Repository\EloquentSocialIdentityRepository;
use Tests\TestCase;
use Throwable;

final class EloquentSocialIdentityRepositoryTest extends TestCase
{
    private PDO $pdo;

    private SocialIdentityModel $identities;

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

        // Post-migration production shape: the identity identifiers are
        // case-sensitive (utf8mb4_bin) and the aliases table keeps its
        // composite primary key plus the identity foreign key.
        $schema = EloquentConnection::fromPdo($this->pdo)->getSchemaBuilder();
        $schema->dropIfExists('social_identity_aliases');
        $schema->dropIfExists('social_identities');
        $schema->create('social_identities', static function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('provider', 16);
            $table->string('provider_app_id', 64)->collation('utf8mb4_bin');
            $table->string('identity_namespace', 64)->collation('utf8mb4_bin');
            $table->string('subject_type', 16);
            $table->string('subject_id', 128)->collation('utf8mb4_bin');
            $table->string('openid', 128)->collation('utf8mb4_bin');
            $table->string('unionid', 128)->nullable()->collation('utf8mb4_bin');
            $table->smallInteger('status')->default(0);
            $table->dateTime('bound_at', 3)->nullable();
            $table->dateTime('last_login_at', 3)->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->unique(['provider', 'identity_namespace', 'subject_type', 'subject_id'], 'uniq_social_subject');
            $table->unique(['provider', 'provider_app_id', 'openid'], 'uniq_provider_openid');
            $table->unique(['user_id', 'provider'], 'uniq_user_provider');
            $table->index('user_id', 'idx_social_user');
            $table->index('status', 'idx_social_status');
        });
        $schema->create('social_identity_aliases', static function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_bin');
            $table->string('provider', 16);
            $table->string('provider_app_id', 64);
            $table->string('openid', 128);
            $table->unsignedBigInteger('social_identity_id');
            $table->primary(['provider', 'provider_app_id', 'openid']);
            $table->index('social_identity_id', 'idx_social_alias_identity');
            $table->foreign('social_identity_id')->references('id')->on('social_identities');
        });

        $this->identities = EloquentConnection::model(SocialIdentityModel::class, $this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $schema = EloquentConnection::fromPdo($this->pdo)->getSchemaBuilder();
            $schema->dropIfExists('social_identity_aliases');
            $schema->dropIfExists('social_identities');
            EloquentConnection::forget($this->pdo);
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
        try {
            // Each worker parks immediately before its social_identities
            // insert inside the Eloquent beforeExecuting hook, so the unique
            // key - not the pre-check read - has to serialize them.
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
            $this->waitForReserveWorkers($workers);

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
            $this->assertSame(1, $this->identities->newQuery()->count());
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
        }
    }

    /** @throws Throwable */
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

    /** @throws Throwable */
    public function testRegistrationIntentResumesAndCannotBeStolenByManualBinding(): void
    {
        $repo = new EloquentSocialIdentityRepository($this->pdo, new Snowflake(1));
        $identity = $repo->reserve($this->identity('registration-openid'));
        $intent = $repo->beginRegistration($identity->id);
        $this->assertSame(3, $intent->status);
        $this->assertSame($intent->id, $repo->beginRegistration($identity->id)->id);
        try {
            $repo->bindToUser($identity->id, '10002');
            $this->fail('Manual bind stole a durable registration intent');
        } catch (RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->value, $e->getCode());
        }
        $bound = $repo->completeRegistration($identity->id, '10001');
        $this->assertSame('10001', $bound->userId);
        $this->assertSame('10001', $repo->completeRegistration($identity->id, '10001')->userId);
        $this->assertSame('10001', $repo->beginRegistration($identity->id)->userId);
    }

    /** @throws Throwable */
    public function testManualBindingWinsBeforeRegistrationIntentWithoutCreatingAUser(): void
    {
        $repo = new EloquentSocialIdentityRepository($this->pdo, new Snowflake(1));
        $identity = $repo->reserve($this->identity('manual-openid'));
        $repo->bindToUser($identity->id, '10001');
        $this->assertSame('10001', $repo->beginRegistration($identity->id)->userId);
        $this->expectExceptionCode(ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->value);
        $repo->completeRegistration($identity->id, '10002');
    }

    /** @throws Throwable */
    public function testUnionPromotionPreservesRegistrationKey(): void
    {
        $repo = new EloquentSocialIdentityRepository($this->pdo, new Snowflake(1));
        $identity = $repo->reserve($this->identity('registration-promotion'));
        $repo->beginRegistration($identity->id);
        $promoted = $repo->findByExternalIdentity($this->identity('registration-promotion', 'registration-union'));
        $this->assertSame($identity->id, $promoted->id);
        $this->assertSame(3, $promoted->status);
        $this->assertSame('10001', $repo->completeRegistration($identity->id, '10001')->userId);
    }

    /** @throws Throwable */
    public function testRegistrationIntentSurvivesAnExistingPendingUnionRow(): void
    {
        $repo = $this->repository();
        $union = $repo->reserve($this->identity('union-app-openid', 'new-unionid', 'other-app'));
        $open = $repo->reserve($this->identity('recover-openid'));
        $repo->beginRegistration($open->id);
        $canonical = $repo->findByExternalIdentity($this->identity('recover-openid', 'new-unionid'));
        $this->assertSame($open->id, $canonical->id);
        $this->assertSame(3, $canonical->status);
        $this->assertNull($repo->findById($union->id));
        $this->assertSame($open->id, $repo->findByExternalIdentity($this->identity('union-app-openid', providerAppId: 'other-app'))->id);
        $this->assertSame('10001', $repo->completeRegistration($open->id, '10001')->userId);
    }

    /** @throws Throwable */
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
        $this->assertSame(1, $this->identities->newQuery()->count());
        $this->assertSame(
            $unionId,
            $this->identities->newQuery()->value('subject_id'),
        );
    }

    /** @throws Throwable */
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
            $this->identity('active-openid', 'active-unionid'),
        );

        $this->assertNotNull($canonical);
        $this->assertSame('4101', $canonical->id);
        $this->assertSame('4100', $canonical->userId);
        $this->assertSame(1, $canonical->status);
        $this->assertSame(1, $this->identities->newQuery()->count());
    }

    /** @throws Throwable */
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
            $this->identity('pending-openid', 'pending-unionid'),
        );

        $this->assertNotNull($canonical);
        $this->assertSame('4201', $canonical->id);
        $this->assertSame('4200', $canonical->userId);
        $this->assertSame(1, $canonical->status);
        $this->assertSame(1, $this->identities->newQuery()->count());
    }

    /** @throws Throwable */
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
            $this->identity('both-pending-openid', 'both-pending-unionid'),
        );

        $this->assertNotNull($canonical);
        $this->assertSame('4301', $canonical->id);
        $this->assertNull($canonical->userId);
        $this->assertSame(0, $canonical->status);
        $this->assertSame(1, $this->identities->newQuery()->count());
    }

    /** @throws Throwable */
    public function testSameUserLegacyUnionAndOpenIdRowsAreMergedAndOpenIdRowRemoved(): void
    {
        $repository = $this->repository();
        // Legacy duplicate rows could exist before uniq_user_provider was added.
        // Drop only that invariant while preparing the fixture, then restore it
        // after reconciliation proves that one canonical row remains.
        EloquentConnection::fromPdo($this->pdo)->getSchemaBuilder()->table(
            'social_identities',
            static function (Blueprint $table): void {
                $table->dropUnique('uniq_user_provider');
            },
        );
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
            $this->identity('merge-openid', 'merge-unionid'),
        );

        $this->assertNotNull($canonical);
        $this->assertSame('2001', $canonical->id);
        $this->assertSame(1, $this->identities->newQuery()->count());
        $this->assertSame('2001', (string)$this->identities->newQuery()->value('id'));
        EloquentConnection::fromPdo($this->pdo)->getSchemaBuilder()->table(
            'social_identities',
            static function (Blueprint $table): void {
                $table->unique(['user_id', 'provider'], 'uniq_user_provider');
            },
        );
    }

    /** @throws Throwable */
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
                $this->identity('conflict-openid', 'conflict-unionid'),
            );
            $this->fail('Expected a unionid/openid identity conflict');
        } catch (RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->value, $e->getCode());
        }

        $this->assertSame(2, $this->identities->newQuery()->count());
    }

    /** @throws Throwable */
    public function testDisabledIdentityStateWinsWhenRowsAreMerged(): void
    {
        $repository = $this->repository();
        EloquentConnection::fromPdo($this->pdo)->getSchemaBuilder()->table(
            'social_identities',
            static function (Blueprint $table): void {
                $table->dropUnique('uniq_user_provider');
            },
        );

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
            $this->identity('disabled-openid', 'disabled-unionid'),
        );

        $this->assertNotNull($canonical);
        $this->assertSame('5100', $canonical->userId);
        $this->assertSame(2, $canonical->status);
        $openidOnly = $repository->findByExternalIdentity($this->identity('disabled-openid'));
        $this->assertSame($canonical->id, $openidOnly->id);
        $this->assertSame(2, $openidOnly->status);
        $this->assertSame(1, $this->identities->newQuery()->count());
    }

    /** @throws Throwable */
    public function testNewAppOpenIdRemainsMappedWithoutUnionIdForEveryCanonicalState(): void
    {
        $repo = $this->repository();
        foreach ([0, 1, 2, 3] as $status) {
            $id = (string)(8100 + $status);
            $this->insertIdentity(
                id: $id,
                providerAppId: 'other-app',
                subjectType: 'unionid',
                subjectId: 'canonical-union-' . $status,
                openId: 'other-openid-' . $status,
                unionId: 'canonical-union-' . $status,
                userId: in_array($status, [1, 2], true) ? (string)(9100 + $status) : null,
                status: $status,
            );
            $resolved = $repo->findByExternalIdentity($this->identity('new-app-openid-' . $status, 'canonical-union-' . $status));
            $this->assertSame($id, $resolved->id);
            $openidOnly = $repo->findByExternalIdentity($this->identity('new-app-openid-' . $status));
            $this->assertSame($id, $openidOnly->id);
            $this->assertSame($status, $openidOnly->status);
        }
    }

    /** @throws RandomException */
    private function repository(): EloquentSocialIdentityRepository
    {
        return new EloquentSocialIdentityRepository($this->pdo, new Snowflake(random_int(1, 1023)));
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
        $this->identities->newQuery()->create([
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
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<int, array{process: resource, pipes: array<int, resource>}> $workers
     */
    private function waitForReserveWorkers(array &$workers): void
    {
        $deadline = microtime(true) + 5.0;
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
