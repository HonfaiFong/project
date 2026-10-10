<?php

declare(strict_types=1);

namespace Services\UserService\Infrastructure\Repository;

use Contracts\Errors\ErrorCode;
use Illuminate\Database\QueryException;
use Packages\Database\EloquentConnection;
use PDO;
use Services\UserService\Domain\Entity\User;
use Services\UserService\Domain\Exception\DuplicateUserException;
use Services\UserService\Domain\Repository\UserRepositoryInterface;
use Services\UserService\Infrastructure\Model\UserModel;

final readonly class EloquentUserRepository implements UserRepositoryInterface
{
    private UserModel $model;

    public function __construct(PDO $pdo)
    {
        $this->model = EloquentConnection::model(UserModel::class, $pdo);
    }

    public function findById(string $id): ?User
    {
        return $this->model->newQuery()->find($id)?->toDomain();
    }

    public function findByIdForUpdate(string $id): ?User
    {
        $query = $this->model->newQuery();
        $query->whereKey($id)->lockForUpdate();

        return $query->first()?->toDomain();
    }

    public function findByPhone(string $phone): ?User
    {
        return $this->model->newQuery()->where('phone', $phone)->first()?->toDomain();
    }

    public function findByEmail(string $email): ?User
    {
        return $this->model->newQuery()->where('email', $email)->first()?->toDomain();
    }

    public function findBySocialRegistrationKey(string $registrationKey): ?User
    {
        return $this->model->newQuery()->where('social_registration_key', $registrationKey)->first()?->toDomain();
    }

    public function socialRegistrationKeyExists(string $registrationKey): bool
    {
        // A deleted account still owns its unique registration key.
        return $this->model->newQueryWithoutScopes()->where('social_registration_key', $registrationKey)->exists();
    }

    public function batchFindByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $query = $this->model->newQuery();
        $query->whereIn('id', array_values($ids));

        return $query->get()->map(static fn (UserModel $model): User => $model->toDomain())->all();
    }

    public function create(
        string $id,
        ?string $phone,
        ?string $email,
        string $registerChannel = 'default',
        int $riskLevel = 0,
        ?string $socialRegistrationKey = null
    ): User {
        $now = gmdate('Y-m-d H:i:s.v');
        try {
            return $this->model->newQuery()->create([
                'id' => $id, 'status' => 1, 'phone' => $phone, 'email' => $email,
                'register_channel' => $registerChannel, 'risk_level' => $riskLevel,
                'social_registration_key' => $socialRegistrationKey,
                'created_at' => $now, 'updated_at' => $now,
            ])->toDomain();
        } catch (QueryException $exception) {
            $this->throwDuplicateUser($exception);
        }
    }

    public function update(string $id, ?string $phone, ?string $email): ?User
    {
        $attributes = ['updated_at' => gmdate('Y-m-d H:i:s.v')];
        if ($phone !== null) {
            $attributes['phone'] = $phone;
        }
        if ($email !== null) {
            $attributes['email'] = $email;
        }
        try {
            $this->model->newQuery()->whereKey($id)->update($attributes);
        } catch (QueryException $exception) {
            $this->throwDuplicateUser($exception);
        }

        return $this->findById($id);
    }

    public function updateLastLogin(string $id): void
    {
        $now = gmdate('Y-m-d H:i:s.v');
        $this->model->newQueryWithoutScopes()->whereKey($id)
            ->update(['last_login_at' => $now, 'last_active_at' => $now]);
    }

    private function throwDuplicateUser(QueryException $exception): never
    {
        if ($exception->getCode() === '23000' || (int)($exception->errorInfo[1] ?? 0) === 1062) {
            throw new DuplicateUserException(
                'User already exists with the same phone, email or registration key',
                ErrorCode::USER_ALREADY_EXISTS->value,
                $exception,
            );
        }

        throw $exception;
    }
}
