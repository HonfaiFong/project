<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Repository;

use PDO;
use PDOException;
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

        $stmt = $this->pdo->prepare(
            'SELECT * FROM devices WHERE user_id = :user_id AND device_uuid = :uuid LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'uuid'    => $deviceUuid,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $update = $this->pdo->prepare(
                'UPDATE devices 
                 SET platform = :platform, app_version = :app_version,
                     push_token = COALESCE(:push_token, push_token),
                     last_active_at = :last_active_at, updated_at = :updated_at
                 WHERE id = :id AND user_id = :user_id'
            );
            $update->execute([
                'platform'    => $platform,
                'app_version' => $appVersion,
                'push_token'  => $pushToken,
                'last_active_at' => $now,
                'updated_at'  => $now,
                'id'          => $row['id'],
                'user_id'     => $userId,
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
             VALUES (:id, :user_id, :device_uuid, :platform, :app_version, :push_token,
                     :last_active_at, :created_at, :updated_at)'
        );
        try {
            $insert->execute([
                'id'          => $id,
                'user_id'     => $userId,
                'device_uuid' => $deviceUuid,
                'platform'    => $platform,
                'app_version' => $appVersion,
                'push_token'  => $pushToken,
                'last_active_at' => $now,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        } catch (PDOException $exception) {
            if (($exception->errorInfo[0] ?? null) !== '23000') {
                throw $exception;
            }

            // Two requests for the same user's device can both miss the
            // initial SELECT. The composite unique key makes one insert win;
            // reuse that committed row instead of surfacing a duplicate error.
            $existing = $this->pdo->prepare(
                'SELECT * FROM devices WHERE user_id = :user_id AND device_uuid = :uuid LIMIT 1'
            );
            $existing->execute([
                'user_id' => $userId,
                'uuid'    => $deviceUuid,
            ]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw $exception;
            }

            return new Device(
                id: (string)$row['id'],
                userId: (string)$row['user_id'],
                deviceUuid: (string)$row['device_uuid'],
                platform: (string)$row['platform'],
                appVersion: (string)$row['app_version'],
                pushToken: $row['push_token'] ? (string)$row['push_token'] : null,
                lastIp: $row['last_ip'] ? (string)$row['last_ip'] : null,
                lastActiveAt: (string)$row['last_active_at'],
                createdAt: (string)$row['created_at'],
                updatedAt: (string)$row['updated_at'],
            );
        }

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

    public function deviceBelongsToUser(string $userId, string $deviceId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM devices WHERE id = :device_id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute([
            'device_id' => $deviceId,
            'user_id'   => $userId,
        ]);

        return $stmt->fetchColumn() !== false;
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
                'SELECT rt.*
                 FROM refresh_tokens AS rt
                 INNER JOIN devices AS d
                    ON d.id = rt.device_id AND d.user_id = rt.user_id
                 WHERE rt.token_hash = :hash
                 LIMIT 1 FOR UPDATE'
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
        $stmt = $this->pdo->prepare(
            'SELECT rt.*
             FROM refresh_tokens AS rt
             INNER JOIN devices AS d
                ON d.id = rt.device_id AND d.user_id = rt.user_id
             WHERE rt.token_hash = :hash
             LIMIT 1'
        );
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
}
