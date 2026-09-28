<?php

declare(strict_types=1);

namespace Services\UserService\Infrastructure\Repository;

use PDO;
use Services\UserService\Domain\Entity\User;
use Services\UserService\Domain\Repository\UserRepositoryInterface;

final class PdoUserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(string $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findByIdForUpdate(string $id): ?User
    {
        $sql = 'SELECT * FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1';
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findByPhone(string $phone): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE phone = :phone AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['phone' => $phone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function batchFindByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id IN ($placeholders) AND deleted_at IS NULL");
        $stmt->execute(array_values($ids));

        $users = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $users[] = $this->hydrate($row);
        }

        return $users;
    }

    public function create(
        string $id,
        ?string $phone,
        ?string $email,
        string $registerChannel = 'default',
        int $riskLevel = 0
    ): User {
        $now = gmdate('Y-m-d H:i:s.v');

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, status, phone, email, register_channel, risk_level, created_at, updated_at)
             VALUES (:id, 1, :phone, :email, :register_channel, :risk_level, :created_at, :updated_at)'
        );
        $stmt->execute([
            'id'               => $id,
            'phone'            => $phone,
            'email'            => $email,
            'register_channel' => $registerChannel,
            'risk_level'       => $riskLevel,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        return new User(
            id: $id,
            status: 1,
            phone: $phone,
            email: $email,
            registerChannel: $registerChannel,
            riskLevel: $riskLevel,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function update(string $id, ?string $phone, ?string $email): ?User
    {
        $now = gmdate('Y-m-d H:i:s.v');
        $stmt = $this->pdo->prepare(
            'UPDATE users 
             SET phone = COALESCE(:phone, phone), email = COALESCE(:email, email), updated_at = :now
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([
            'phone' => $phone,
            'email' => $email,
            'now'   => $now,
            'id'    => $id,
        ]);

        return $this->findById($id);
    }

    public function updateLastLogin(string $id): void
    {
        $now = gmdate('Y-m-d H:i:s.v');
        $stmt = $this->pdo->prepare('UPDATE users SET last_login_at = :now, last_active_at = :now WHERE id = :id');
        $stmt->execute(['now' => $now, 'id' => $id]);
    }

    private function hydrate(array $row): User
    {
        return new User(
            id: (string)$row['id'],
            status: (int)$row['status'],
            phone: $row['phone'] ? (string)$row['phone'] : null,
            email: $row['email'] ? (string)$row['email'] : null,
            registerChannel: (string)($row['register_channel'] ?? 'default'),
            riskLevel: (int)($row['risk_level'] ?? 0),
            lastLoginAt: $row['last_login_at'] ? (string)$row['last_login_at'] : null,
            lastActiveAt: $row['last_active_at'] ? (string)$row['last_active_at'] : null,
            createdAt: (string)$row['created_at'],
            updatedAt: (string)$row['updated_at'],
        );
    }
}
