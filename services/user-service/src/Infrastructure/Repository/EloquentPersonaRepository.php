<?php

declare(strict_types=1);

namespace Services\UserService\Infrastructure\Repository;

use PDO;
use Packages\Database\EloquentConnection;
use Services\UserService\Domain\Entity\Persona;
use Services\UserService\Domain\Repository\PersonaRepositoryInterface;
use Services\UserService\Infrastructure\Model\PersonaModel;

final readonly class EloquentPersonaRepository implements PersonaRepositoryInterface
{
    private PersonaModel $model;

    public function __construct(PDO $pdo)
    {
        $this->model = EloquentConnection::model(PersonaModel::class, $pdo);
    }

    public function findById(string $id): ?Persona
    {
        $query = $this->model->newQuery();
        $query
            ->where('id', $id)
            ->whereNull('deleted_at');

        return $query->first()?->toDomain();
    }

    public function batchFindByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $query = $this->model->newQuery();
        $query
            ->whereIn('id', array_values($ids))
            ->whereNull('deleted_at');
        return $query->get()->map(static fn (PersonaModel $model): Persona => $model->toDomain())->all();
    }

    public function findByUserId(string $userId): array
    {
        $query = $this->model->newQuery();
        $query
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->orderBy('created_at');
        return $query->get()->map(static fn (PersonaModel $model): Persona => $model->toDomain())->all();
    }

    public function countByUserId(string $userId): int
    {
        return $this->model->newQuery()
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->count();
    }

    public function create(
        string $id,
        string $userId,
        int $type,
        string $nickname,
        ?string $avatarKey,
        ?string $bio
    ): Persona {
        $now = gmdate('Y-m-d H:i:s.v');

        $attributes = [
            'id'         => $id,
            'user_id'    => $userId,
            'type'       => $type,
            'nickname'   => $nickname,
            'avatar_key' => $avatarKey,
            'bio'        => $bio,
            'status'     => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $model = $this->model->newQuery()->create($attributes);

        return $model->toDomain();
    }

    public function update(
        string $id,
        string $userId,
        ?string $nickname,
        ?string $avatarKey,
        ?string $bio
    ): ?Persona {
        $now = gmdate('Y-m-d H:i:s.v');

        // Same COALESCE semantics as the previous SQL: a null argument keeps
        // the stored column untouched.
        $attributes = ['updated_at' => $now];
        if ($nickname !== null) {
            $attributes['nickname'] = $nickname;
        }
        if ($avatarKey !== null) {
            $attributes['avatar_key'] = $avatarKey;
        }
        if ($bio !== null) {
            $attributes['bio'] = $bio;
        }

        $this->model->newQuery()
            ->where('id', $id)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->update($attributes);

        return $this->findById($id);
    }
}
