<?php

declare(strict_types=1);

namespace Services\UserService\Infrastructure\Repository;

use PDO;
use Services\UserService\Domain\Entity\Persona;
use Services\UserService\Domain\Repository\PersonaRepositoryInterface;

final class PdoPersonaRepository implements PersonaRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(string $id): ?Persona
    {
        $stmt = $this->pdo->prepare('SELECT * FROM personas WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function batchFindByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM personas WHERE id IN ($placeholders) AND deleted_at IS NULL");
        $stmt->execute(array_values($ids));

        $personas = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $personas[] = $this->hydrate($row);
        }

        return $personas;
    }

    public function findByUserId(string $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM personas WHERE user_id = :user_id AND deleted_at IS NULL ORDER BY created_at ASC');
        $stmt->execute(['user_id' => $userId]);

        $personas = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $personas[] = $this->hydrate($row);
        }

        return $personas;
    }

    public function countByUserId(string $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM personas WHERE user_id = :user_id AND deleted_at IS NULL');
        $stmt->execute(['user_id' => $userId]);
        return (int)$stmt->fetchColumn();
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

        $stmt = $this->pdo->prepare(
            'INSERT INTO personas (id, user_id, type, nickname, avatar_key, bio, status, created_at, updated_at)
             VALUES (:id, :user_id, :type, :nickname, :avatar_key, :bio, 1, :created_at, :updated_at)'
        );
        $stmt->execute([
            'id'         => $id,
            'user_id'    => $userId,
            'type'       => $type,
            'nickname'   => $nickname,
            'avatar_key' => $avatarKey,
            'bio'        => $bio,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new Persona(
            id: $id,
            userId: $userId,
            type: $type,
            nickname: $nickname,
            avatarKey: $avatarKey,
            bio: $bio,
            status: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function update(
        string $id,
        string $userId,
        ?string $nickname,
        ?string $avatarKey,
        ?string $bio
    ): ?Persona {
        $now = gmdate('Y-m-d H:i:s.v');

        $stmt = $this->pdo->prepare(
            'UPDATE personas 
             SET nickname = COALESCE(:nickname, nickname), 
                 avatar_key = COALESCE(:avatar_key, avatar_key), 
                 bio = COALESCE(:bio, bio), 
                 updated_at = :now
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
        );
        $stmt->execute([
            'nickname'   => $nickname,
            'avatar_key' => $avatarKey,
            'bio'        => $bio,
            'now'        => $now,
            'id'         => $id,
            'user_id'    => $userId,
        ]);

        return $this->findById($id);
    }

    private function hydrate(array $row): Persona
    {
        return new Persona(
            id: (string)$row['id'],
            userId: (string)$row['user_id'],
            type: (int)$row['type'],
            nickname: (string)$row['nickname'],
            avatarKey: $row['avatar_key'] ? (string)$row['avatar_key'] : null,
            bio: $row['bio'] ? (string)$row['bio'] : null,
            status: (int)$row['status'],
            createdAt: (string)$row['created_at'],
            updatedAt: (string)$row['updated_at'],
        );
    }
}
