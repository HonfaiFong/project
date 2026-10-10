<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contracts\Errors\ErrorCode;
use Contracts\Events\EventTypes;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use JsonException;
use Packages\Database\EloquentConnection;
use Packages\Identity\PersonaOperation;
use Packages\Identity\PersonaMode;
use Packages\IdGenerator\Snowflake;
use Packages\Outbox\Model\OutboxEvent;
use Packages\Outbox\OutboxRepository;
use Packages\Phone\PhoneNormalizer;
use PDO;
use PDOException;
use RuntimeException;
use Services\UserService\Application\Service\UserApplicationService;
use Services\UserService\Domain\Exception\DuplicateUserException;
use Services\UserService\Infrastructure\Model\PersonaModel;
use Services\UserService\Infrastructure\Model\UserModel;
use Services\UserService\Infrastructure\Repository\EloquentPersonaRepository;
use Services\UserService\Infrastructure\Repository\EloquentTransactionManager;
use Services\UserService\Infrastructure\Repository\EloquentUserRepository;
use Tests\TestCase;

final class UserOutboxTransactionTest extends TestCase
{
    private PDO $pdo;
    private UserApplicationService $service;
    private UserModel $users;
    private PersonaModel $personas;
    private OutboxEvent $outbox;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = EloquentConnection::fromPdo($this->pdo)->getSchemaBuilder();
        $schema->create('users', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->integer('status');
            $table->string('phone')->nullable()->unique();
            $table->string('email')->nullable();
            $table->string('register_channel');
            $table->string('social_registration_key')->nullable()->unique();
            $table->integer('risk_level');
            $table->string('last_login_at')->nullable();
            $table->string('last_active_at')->nullable();
            $table->string('created_at');
            $table->string('updated_at');
            $table->string('deleted_at')->nullable();
        });
        $schema->create('personas', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->integer('type');
            $table->unsignedTinyInteger('is_internal_alias')->default(0);
            $table->string('nickname');
            $table->string('avatar_key')->nullable();
            $table->string('bio')->nullable();
            $table->integer('status');
            $table->string('created_at');
            $table->string('updated_at');
            $table->string('deleted_at')->nullable();
        });
        $schema->create('outbox_events', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('event_id')->unique();
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->string('event_key');
            $table->string('trace_id');
            $table->string('event_type');
            $table->integer('event_version');
            $table->text('payload');
            $table->integer('status');
            $table->string('available_at');
            $table->string('created_at');
        });

        $this->users = EloquentConnection::model(UserModel::class, $this->pdo);
        $this->personas = EloquentConnection::model(PersonaModel::class, $this->pdo);
        $this->outbox = EloquentConnection::model(OutboxEvent::class, $this->pdo);

        $snowflake = new Snowflake(1);
        $this->service = new UserApplicationService(
            userRepo: new EloquentUserRepository($this->pdo),
            personaRepo: new EloquentPersonaRepository($this->pdo),
            snowflake: $snowflake,
            transactionManager: new EloquentTransactionManager($this->pdo),
            outbox: new OutboxRepository($this->pdo, $snowflake),
            phoneNormalizer: new PhoneNormalizer(),
        );
    }

    protected function tearDown(): void
    {
        EloquentConnection::forget($this->pdo);
        parent::tearDown();
    }

    /** @throws JsonException */
    public function testRegistrationCommitsUserPersonaAndOutboxEvents(): void
    {
        $result = $this->service->findOrCreateUserByPhone('+8613800000000');
        $userId = $result['user']->id;

        $this->assertTrue($result['is_new_user']);
        $this->assertSame($userId, (string)$this->users->newQuery()->value('id'));
        $this->assertSame($userId, (string)$this->personas->newQuery()->value('user_id'));
        $events = $this->outbox->newQuery()
            ->get(['aggregate_type', 'aggregate_id', 'event_key', 'trace_id', 'event_type', 'payload'])
            ->toArray();
        $this->assertCount(3, $events);
        $userEvent = array_values(array_filter(
            $events,
            static fn (array $event): bool => $event['event_type'] === EventTypes::USER_REGISTERED,
        ))[0];
        $personaEvents = array_values(array_filter(
            $events,
            static fn (array $event): bool => $event['event_type'] === EventTypes::PERSONA_UPDATED,
        ));
        $this->assertCount(2, $personaEvents);
        $personaEvent = $personaEvents[0];
        $this->assertSame('persona', $personaEvent['aggregate_type']);
        $this->assertSame((string)$this->personas->newQuery()->where('type', 1)->value('id'), $personaEvent['aggregate_id']);
        $this->assertSame($userId, json_decode($personaEvent['payload'], true, flags: JSON_THROW_ON_ERROR)['user_id']);
        $this->assertSame('user', $userEvent['aggregate_type']);
        $this->assertSame($userId, $userEvent['aggregate_id']);
        $this->assertSame($userId, $userEvent['event_key']);
        $this->assertSame($userId, $personaEvent['event_key']);
        $this->assertNotSame('', $userEvent['trace_id']);
        $this->assertSame($userEvent['trace_id'], $personaEvent['trace_id']);
        $this->assertSame($userId, json_decode($userEvent['payload'], true, flags: JSON_THROW_ON_ERROR)['id']);
        $this->assertSame(
            (string)$this->personas->newQuery()->where('is_internal_alias', 1)->value('id'),
            $personaEvents[1]['aggregate_id'],
        );
    }

    public function testOutboxInsertFailureRollsBackUserAndPersona(): void
    {
        $this->failOutboxInserts();

        try {
            $this->service->findOrCreateUserByPhone('+8613800000000');
            $this->fail('Expected the outbox insert to fail');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('outbox insert failed', $exception->getMessage());
        }

        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(0, $this->users->newQueryWithoutScopes()->count());
        $this->assertSame(0, $this->personas->newQuery()->count());
        $this->assertSame(0, $this->outbox->newQuery()->count());
    }

    public function testSocialRegistrationPersistsKeyAndReusesTheSameAccount(): void
    {
        $first = $this->service->findOrCreateUserBySocialIdentity('social:1001', 'wechat');
        $userId = $first['user']->id;

        $this->assertTrue($first['is_new_user']);
        $this->assertNull($first['user']->phone);
        $this->assertSame('wechat', $first['user']->registerChannel);
        $this->assertSame(
            $userId,
            (string)$this->users->newQuery()->where('social_registration_key', 'social:1001')->value('id'),
        );
        $this->assertSame($userId, (string)$this->personas->newQuery()->value('user_id'));
        $this->assertSame(3, $this->outbox->newQuery()->count());

        $second = $this->service->findOrCreateUserBySocialIdentity('social:1001', 'qq');

        $this->assertFalse($second['is_new_user']);
        $this->assertSame($userId, $second['user']->id);
        $this->assertSame('wechat', $second['user']->registerChannel);
        $this->assertSame(1, $this->users->newQueryWithoutScopes()->count());
        $this->assertSame(2, $this->personas->newQuery()->count());
        $this->assertSame(3, $this->outbox->newQuery()->count());
    }

    public function testSocialRegistrationKeyUniquenessRejectsASecondAccount(): void
    {
        $this->service->findOrCreateUserBySocialIdentity('social:1001');

        try {
            $this->users->newQuery()->create([
                'id' => '9999',
                'status' => 1,
                'phone' => null,
                'email' => null,
                'register_channel' => 'default',
                'social_registration_key' => 'social:1001',
                'risk_level' => 0,
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ]);
            $this->fail('Expected the duplicate social registration key to be rejected');
        } catch (QueryException $exception) {
            // SQLite 将约束违反报告为 SQLSTATE 23000、驱动代码 19，并指出拒绝第二个账户的唯一键。
            $this->assertSame('23000', (string)$exception->getCode());
            $this->assertSame(19, (int)($exception->errorInfo[1] ?? 0));
            $this->assertStringContainsString(
                'UNIQUE constraint failed: users.social_registration_key',
                $exception->getMessage(),
            );
        }

        // 被拒绝的插入未修改上方创建的账户。
        $this->assertSame(1, $this->users->newQueryWithoutScopes()->count());
    }

    public function testDuplicateRegistrationKeyIsReportedAsDuplicateUserException(): void
    {
        $repo = new EloquentUserRepository($this->pdo);
        $repo->create('40001', null, null, 'wechat', 0, 'social:1001');

        $this->expectException(DuplicateUserException::class);
        $repo->create('40002', null, null, 'wechat', 0, 'social:1001');
    }

    public function testSoftDeletedAccountCannotBeReRegisteredThroughItsKey(): void
    {
        $first = $this->service->findOrCreateUserBySocialIdentity('social:1001');
        $this->users->newQuery()
            ->whereKey($first['user']->id)
            ->update(['deleted_at' => '2026-01-01 00:00:00']);

        try {
            $this->service->findOrCreateUserBySocialIdentity('social:1001');
            $this->fail('Expected the soft-deleted account to block re-registration');
        } catch (RuntimeException $e) {
            // 唯一键仍占用已删除行的注册键，因此重试须拒绝。
            $this->assertSame(ErrorCode::USER_NOT_FOUND->value, $e->getCode());
        }

        $this->assertSame(1, $this->users->newQueryWithoutScopes()->count());
        $this->assertSame(2, $this->personas->newQuery()->count());
    }

    public function testSoftDeletedCanonicalAliasCannotBeRecreated(): void
    {
        $result = $this->service->findOrCreateUserByPhone('+8613800000000');
        $aliasId = (string)$this->personas->newQuery()->where('is_internal_alias', 1)->value('id');
        $this->personas->newQuery()->whereKey($aliasId)->update([
            'deleted_at' => '2026-01-01 00:00:00',
        ]);

        $this->expectExceptionCode(ErrorCode::PERSONA_FORBIDDEN->value);
        $this->service->resolvePersonaMode(
            $result['user']->id,
            PersonaMode::ALIAS->value,
            PersonaOperation::CREATE_POST->value,
        );
    }

    public function testOutboxInsertFailureRollsBackSocialRegistrationToo(): void
    {
        $this->failOutboxInserts();

        try {
            $this->service->findOrCreateUserBySocialIdentity('social:1001');
            $this->fail('Expected the outbox insert to fail');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('outbox insert failed', $exception->getMessage());
        }

        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(0, $this->users->newQueryWithoutScopes()->count());
        $this->assertSame(0, $this->personas->newQuery()->count());
        $this->assertSame(0, $this->outbox->newQuery()->count());
    }

    public function testPersonaNullableFieldsPreserveEmptyAndZeroStrings(): void
    {
        $repository = new EloquentPersonaRepository($this->pdo);
        foreach ([null, '', '0'] as $index => $value) {
            $persona = $repository->create((string)(40000 + $index), '1001', 1, 'nickname', $value, $value);
            $this->assertSame($value, $persona->avatarKey);
            $this->assertSame($value, $persona->bio);
            $this->assertSame($value, $repository->findById($persona->id)?->avatarKey);
            $this->assertSame($value, $repository->findById($persona->id)?->bio);
        }
    }

    /**
     * 替代已移除的 SQLite“reject_outbox_insert”触发器：在真实事务内，紧接 Outbox 插入之前，通过 Eloquent 连接抛出失
     * 败。
     */
    private function failOutboxInserts(): void
    {
        EloquentConnection::fromPdo($this->pdo)->beforeExecuting(
            static function (string $query): void {
                if (str_starts_with($query, 'insert ') && str_contains($query, 'outbox_events')) {
                    throw new PDOException('outbox insert failed');
                }
            },
        );
    }
}
