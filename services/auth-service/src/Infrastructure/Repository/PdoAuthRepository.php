<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Repository;

use PDO;
use Packages\IdGenerator\Snowflake;
use Services\AuthService\Domain\Entity\Device;
use Services\AuthService\Domain\Entity\RefreshToken;
use Services\AuthService\Domain\Repository\AuthRepositoryInterface;

final class PdoAuthRepository implements AuthRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Snowflake $snowflake
    ) {
    }

    public function findOrCreateDevice(
        string $userId,
        string $deviceUuid,
        string $platform,
        string $appVersion,
        ?string $pushToken,
        ?string $ip
    ): Device {
        $now = gmdate('Y-m-d H:i:s.v');

        $stmt = $this->pdo->prepare('SELECT * FROM devices WHERE device_uuid = :uuid LIMIT 1');
        $stmt->execute(['uuid' => $deviceUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $update = $this->pdo->prepare(
                'UPDATE devices 
                 SET user_id = :user_id, platform = :platform, app_version = :app_version, 
                     push_token = COALESCE(:push_token, push_token), last_active_at = :now, updated_at = :now
                 WHERE id = :id'
            );
            $update->execute([
                'user_id'     => $userId,
                'platform'    => $platform,
                'app_version' => $appVersion,
                'push_token'  => $pushToken,
                'now'         => $now,
                'id'          => $row['id'],
            ]);

            return new Device(
                id: (string)$row['id'],
                userId: $userId,
                deviceUuid: $deviceUuid,
                platform: $platform,
                appVersion: $appVersion,
                pushToken: $pushToken ?: ($row['push_token'] ? (string)$row['push_token'] : null),
                lastActiveAt: $now,
                createdAt: (string)$row['created_at'],
                updatedAt: $now,
            );
        }

        $id = $this->snowflake->nextId();
        $insert = $this->pdo->prepare(
            'INSERT INTO devices 
             (id, user_id, device_uuid, platform, app_version, push_token, last_active_at, created_at, updated_at)
             VALUES (:id, :user_id, :device_uuid, :platform, :app_version, :push_token, :now, :now, :now)'
        );
        $insert->execute([
            'id'          => $id,
            'user_id'     => $userId,
            'device_uuid' => $deviceUuid,
            'platform'    => $platform,
            'app_version' => $appVersion,
            'push_token'  => $pushToken,
            'now'         => $now,
        ]);

        return new Device(
            id: $id,
            userId: $userId,
            deviceUuid: $deviceUuid,
            platform: $platform,
            appVersion: $appVersion,
            pushToken: $pushToken,
            lastActiveAt: $now,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function createRefreshToken(
        string $userId,
        string $deviceId,
        string $tokenHash,
        string $expiresAt
    ): RefreshToken {
        $id = $this->snowflake->nextId();
        $now = gmdate('Y-m-d H:i:s.v');

        $stmt = $this->pdo->prepare(
            'INSERT INTO refresh_tokens (id, user_id, device_id, token_hash, expires_at, created_at)
             VALUES (:id, :user_id, :device_id, :token_hash, :expires_at, :created_at)'
        );
        $stmt->execute([
            'id'         => $id,
            'user_id'    => $userId,
            'device_id'  => $deviceId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        return new RefreshToken(
            id: $id,
            userId: $userId,
            deviceId: $deviceId,
            tokenHash: $tokenHash,
            expiresAt: $expiresAt,
            createdAt: $now,
        );
    }

    public function rotateRefreshToken(
        string $tokenHash,
        string $newTokenHash,
        string $newExpiresAt
    ): ?RefreshToken {
        $now = gmdate('Y-m-d H:i:s.v');

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM refresh_tokens WHERE token_hash = :hash LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['hash' => $tokenHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->pdo->commit();
                return null;
            }

            $record = new RefreshToken(
                id: (string)$row['id'],
                userId: (string)$row['user_id'],
                deviceId: (string)$row['device_id'],
                tokenHash: (string)$row['token_hash'],
                expiresAt: (string)$row['expires_at'],
                revokedAt: $row['revoked_at'] ? (string)$row['revoked_at'] : null,
                createdAt: (string)$row['created_at'],
            );

            if (!$record->isValid()) {
                $this->pdo->commit();
                return null;
            }

            $revoke = $this->pdo->prepare(
                'UPDATE refresh_tokens
                 SET revoked_at = :now
                 WHERE id = :id AND revoked_at IS NULL'
            );
            $revoke->execute(['now' => $now, 'id' => $record->id]);

            if ($revoke->rowCount() !== 1) {
                $this->pdo->rollBack();
                return null;
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO refresh_tokens (id, user_id, device_id, token_hash, expires_at, created_at)
                 VALUES (:id, :user_id, :device_id, :token_hash, :expires_at, :created_at)'
            );
            $insert->execute([
                'id'         => $this->snowflake->nextId(),
                'user_id'    => $record->userId,
                'device_id'  => $record->deviceId,
                'token_hash' => $newTokenHash,
                'expires_at' => $newExpiresAt,
                'created_at' => $now,
            ]);

            $this->pdo->commit();

            return $record;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function findRefreshToken(string $tokenHash): ?RefreshToken
    {
        $stmt = $this->pdo->prepare('SELECT * FROM refresh_tokens WHERE token_hash = :hash LIMIT 1');
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return new RefreshToken(
            id: (string)$row['id'],
            userId: (string)$row['user_id'],
            deviceId: (string)$row['device_id'],
            tokenHash: (string)$row['token_hash'],
            expiresAt: (string)$row['expires_at'],
            revokedAt: $row['revoked_at'] ? (string)$row['revoked_at'] : null,
            createdAt: (string)$row['created_at'],
        );
    }

    public function revokeRefreshToken(string $tokenHash): bool
    {
        $now = gmdate('Y-m-d H:i:s.v');
        $stmt = $this->pdo->prepare('UPDATE refresh_tokens SET revoked_at = :now WHERE token_hash = :hash AND revoked_at IS NULL');
        $stmt->execute(['now' => $now, 'hash' => $tokenHash]);
        return $stmt->rowCount() > 0;
    }

    public function revokeUserTokens(string $userId): void
    {
        $now = gmdate('Y-m-d H:i:s.v');
        $stmt = $this->pdo->prepare('UPDATE refresh_tokens SET revoked_at = :now WHERE user_id = :user_id AND revoked_at IS NULL');
        $stmt->execute(['now' => $now, 'user_id' => $userId]);
    }
}
