<?php

declare(strict_types=1);

namespace Tests\Unit;

use Packages\IdGenerator\Snowflake;
use Illuminate\Database\QueryException;
use PDO;
use PDOException;
use Throwable;
use Illuminate\Database\Schema\Blueprint;
use Packages\Database\EloquentConnection;
use Services\AuthService\Infrastructure\Repository\EloquentAuthRepository;
use Tests\TestCase;

final class EloquentAuthRepositoryTest extends TestCase
{
    private PDO $pdo;
    private EloquentAuthRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = EloquentConnection::fromPdo($this->pdo)->getSchemaBuilder();
        $schema->create('devices', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('device_uuid', 64);
            $table->string('platform', 16);
            $table->string('app_version', 32);
            $table->string('push_token')->nullable();
            $table->binary('last_ip')->nullable();
            $table->dateTime('last_active_at');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['user_id', 'device_uuid']);
        });
        $schema->create('refresh_tokens', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('device_id');
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('logged_out_at', 6)->nullable();
            $table->dateTime('created_at');
        });
        $this->repository = new EloquentAuthRepository($this->pdo, new Snowflake());
    }

    /** @throws Throwable */
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
        $this->assertSame(2, EloquentConnection::fromPdo($this->pdo)->table('devices')->count());

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
        $this->assertSame(2, EloquentConnection::fromPdo($this->pdo)->table('devices')->count());
        $this->assertSame(
            '1001',
            (string)EloquentConnection::fromPdo($this->pdo)->table('devices')->where('id', $first->id)->value('user_id'),
        );
        $this->assertSame(
            '1002',
            (string)EloquentConnection::fromPdo($this->pdo)->table('devices')->where('id', $second->id)->value('user_id'),
        );

        $this->assertTrue($this->repository->deviceBelongsToUser('1001', $first->id));
        $this->assertFalse($this->repository->deviceBelongsToUser('1002', $first->id));
        $this->assertTrue($this->repository->deviceBelongsToUser('1002', $second->id));
    }

    /** @throws Throwable */
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

    /** @throws Throwable */
    public function testRefreshRotationRevokesTheOldTokenAndCannotBeRepeated(): void
    {
        $device = $this->repository->findOrCreateDevice('1001', 'rotation', 'ios', '1', null, null);
        $oldHash = str_repeat('b', 64);
        $newHash = str_repeat('c', 64);
        $this->repository->createRefreshToken('1001', $device->id, $oldHash, '2099-01-01 00:00:00.000');

        $old = $this->repository->rotateRefreshToken($oldHash, $newHash, '2099-01-01 00:00:00.000');

        $this->assertSame($oldHash, $old?->tokenHash);
        $this->assertSame('1001', $this->repository->findRefreshToken($newHash)?->userId);
        $this->assertFalse($this->repository->findRefreshToken($oldHash)->isValid());
        $this->assertNull($this->repository->rotateRefreshToken($oldHash, str_repeat('d', 64), '2099-01-01 00:00:00.000'));
        $this->assertFalse($this->pdo->inTransaction());
    }

    /** @throws Throwable */
    public function testFailedRefreshInsertRollsBackTheRevocation(): void
    {
        $device = $this->repository->findOrCreateDevice('1001', 'rollback', 'ios', '1', null, null);
        $oldHash = str_repeat('e', 64);
        $duplicateHash = str_repeat('f', 64);
        $this->repository->createRefreshToken('1001', $device->id, $oldHash, '2099-01-01 00:00:00.000');
        $this->repository->createRefreshToken('1001', $device->id, $duplicateHash, '2099-01-01 00:00:00.000');

        try {
            $this->repository->rotateRefreshToken($oldHash, $duplicateHash, '2099-01-01 00:00:00.000');
            $this->fail('A duplicate replacement token must fail');
        } catch (QueryException $exception) {
            $this->assertNotNull($exception->getPrevious());
        }

        $this->assertTrue($this->repository->findRefreshToken($oldHash)->isValid());
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testNullPushTokenDoesNotOverwriteAConcurrentDeviceUpdate(): void
    {
        $device = $this->repository->findOrCreateDevice('1001', 'concurrent', 'ios', '1', 'old', null);
        $connection = EloquentConnection::fromPdo($this->pdo);
        $interleaved = false;
        $connection->beforeExecuting(static function (string $query) use ($connection, $device, &$interleaved): void {
            if (!$interleaved && str_starts_with($query, 'update ')) {
                $interleaved = true;
                $connection->table('devices')->where('id', $device->id)->update(['push_token' => 'new']);
            }
        });

        $this->repository->findOrCreateDevice('1001', 'concurrent', 'ios', '2', null, null);

        $this->assertTrue($interleaved);
        $this->assertSame('new', $connection->table('devices')->where('id', $device->id)->value('push_token'));
    }

    /** @throws Throwable */
    public function testDatabaseAbortedTransactionDoesNotLeakIntoTheNextRotation(): void
    {
        $device = $this->repository->findOrCreateDevice('1001', 'aborted', 'ios', '1', null, null);
        $oldHash = str_repeat('1', 64);
        $newHash = str_repeat('2', 64);
        $this->repository->createRefreshToken('1001', $device->id, $oldHash, '2099-01-01 00:00:00.000');
        $connection = EloquentConnection::fromPdo($this->pdo);
        $aborted = false;
        $connection->beforeExecuting(function (string $query) use (&$aborted): void {
            if (!$aborted && str_starts_with($query, 'update ')) {
                $aborted = true;
                // 模拟数据库在报告死锁前中止事务。
                $this->pdo->rollBack();
                throw new PDOException('Deadlock found when trying to get lock', 40001);
            }
        });

        try {
            $this->repository->rotateRefreshToken($oldHash, $newHash, '2099-01-01 00:00:00.000');
            $this->fail('The database abort must propagate');
        } catch (PDOException $exception) {
            $this->assertSame(40001, $exception->getCode());
        }
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertNotNull($this->repository->rotateRefreshToken($oldHash, $newHash, '2099-01-01 00:00:00.000'));
        $this->assertTrue($this->repository->findRefreshToken($newHash)->isValid());
    }
}
