<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Repository;

use Illuminate\Database\QueryException;
use Packages\IdGenerator\Snowflake;
use Packages\Database\EloquentConnection;
use PDO;
use PDOException;
use Throwable;
use Services\AuthService\Domain\Entity\Device;
use Services\AuthService\Domain\Entity\RefreshToken;
use Services\AuthService\Domain\Repository\AuthRepositoryInterface;
use Services\AuthService\Infrastructure\Model\Device as DeviceModel;
use Services\AuthService\Infrastructure\Model\RefreshToken as RefreshTokenModel;

final readonly class EloquentAuthRepository implements AuthRepositoryInterface
{
    private DeviceModel $device;
    private RefreshTokenModel $refreshToken;

    public function __construct(
        PDO $pdo,
        private Snowflake $snowflake
    ) {
        $this->device = EloquentConnection::model(DeviceModel::class, $pdo);
        $this->refreshToken = EloquentConnection::model(RefreshTokenModel::class, $pdo);
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

        $device = $this->device->newQuery()
            ->where('user_id', $userId)
            ->where('device_uuid', $deviceUuid)
            ->first();

        if ($device !== null) {
            // 保持原 SQL 的 COALESCE(:push_token, push_token) 语义：推送令牌为 null 时，保留行中已有值。
            $device->setAttribute('platform', $platform);
            $device->setAttribute('app_version', $appVersion);
            $device->setAttribute('push_token', $pushToken ?? $device->getAttribute('push_token'));
            $device->setAttribute('last_active_at', $now);
            $device->setAttribute('updated_at', $now);
            $attributes = $device->only([
                'platform', 'app_version', 'last_active_at', 'updated_at',
            ]);
            if ($pushToken !== null) {
                $attributes['push_token'] = $pushToken;
            }
            $device->newQuery()->whereKey($device->getKey())->where('user_id', $userId)->update($attributes);

            $row = $device->getAttributes();

            return new Device(
                id: (string)$device->getKey(),
                userId: $userId,
                deviceUuid: $deviceUuid,
                platform: (string)$row['platform'],
                appVersion: (string)$row['app_version'],
                pushToken: $row['push_token'] !== null ? (string)$row['push_token'] : null,
                lastActiveAt: $now,
                createdAt: (string)$row['created_at'],
                updatedAt: $now,
            );
        }

        $id = $this->snowflake->nextId();
        try {
            $this->device->newQuery()->create([
                'id' => $id,
                'user_id' => $userId,
                'device_uuid' => $deviceUuid,
                'platform' => $platform,
                'app_version' => $appVersion,
                'push_token' => $pushToken,
                'last_active_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException $exception) {
            if (!$this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            // 同一用户设备的两个请求可能都未在首次 SELECT 中查到记录。复合唯一键保证只有一次插入成功；随后复用已提交的行，避
            // 免向外抛出重复键错误。
            $existing = $this->device->newQuery()
                ->where('user_id', $userId)
                ->where('device_uuid', $deviceUuid)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            $row = $existing->getAttributes();

            return new Device(
                id: (string)$row['id'],
                userId: (string)$row['user_id'],
                deviceUuid: (string)$row['device_uuid'],
                platform: (string)$row['platform'],
                appVersion: (string)$row['app_version'],
                pushToken: $row['push_token'] !== null ? (string)$row['push_token'] : null,
                lastIp: $row['last_ip'] !== null ? (string)$row['last_ip'] : null,
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
        return $this->device->newQuery()
            ->whereKey($deviceId)
            ->where('user_id', $userId)
            ->exists();
    }

    public function createRefreshToken(
        string $userId,
        string $deviceId,
        string $tokenHash,
        string $expiresAt
    ): RefreshToken {
        $id = $this->snowflake->nextId();
        $now = gmdate('Y-m-d H:i:s.v');

        $this->refreshToken->newQuery()->create([
            'id' => $id,
            'user_id' => $userId,
            'device_id' => $deviceId,
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

    /** @throws Throwable 令牌轮换事务失败时抛出。 */
    public function rotateRefreshToken(
        string $tokenHash,
        string $newTokenHash,
        string $newExpiresAt
    ): ?RefreshToken {
        $now = gmdate('Y-m-d H:i:s.v');

        return $this->refreshToken->getConnection()->transaction(function () use ($tokenHash, $newTokenHash, $newExpiresAt, $now): ?RefreshToken {
            $query = $this->refreshToken->newQuery();
            $query->from('refresh_tokens as rt')
                ->select('rt.*')
                ->join('devices as d', function ($join): void {
                    $join->on('d.id', '=', 'rt.device_id')->on('d.user_id', '=', 'rt.user_id');
                })
                ->where('rt.token_hash', $tokenHash)
                ->lockForUpdate();
            $row = $query->first();

            if ($row === null) {
                return null;
            }

            $attributes = $row->getAttributes();

            $record = new RefreshToken(
                id: (string)$attributes['id'],
                userId: (string)$attributes['user_id'],
                deviceId: (string)$attributes['device_id'],
                tokenHash: (string)$attributes['token_hash'],
                expiresAt: (string)$attributes['expires_at'],
                revokedAt: $attributes['revoked_at'] !== null ? (string)$attributes['revoked_at'] : null,
                createdAt: (string)$attributes['created_at'],
            );

            if (!$record->isValid()) {
                return null;
            }

            $revoked = $this->refreshToken->newQuery()
                ->whereKey($record->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now]);

            if ($revoked !== 1) {
                return null;
            }

            $this->refreshToken->newQuery()->create([
                'id' => $this->snowflake->nextId(),
                'user_id' => $record->userId,
                'device_id' => $record->deviceId,
                'token_hash' => $newTokenHash,
                'expires_at' => $newExpiresAt,
                'created_at' => $now,
            ]);

            return $record;
        });
    }

    public function findRefreshToken(string $tokenHash): ?RefreshToken
    {
        $query = $this->refreshToken->newQuery();
        $query->from('refresh_tokens as rt')
            ->select('rt.*')
            ->join('devices as d', function ($join): void {
                $join->on('d.id', '=', 'rt.device_id')->on('d.user_id', '=', 'rt.user_id');
            })
            ->where('rt.token_hash', $tokenHash);
        /** @var RefreshTokenModel|null $row */
        $row = $query->first();

        if ($row === null) {
            return null;
        }

        $attributes = $row->getAttributes();

        return new RefreshToken(
            id: (string)$attributes['id'],
            userId: (string)$attributes['user_id'],
            deviceId: (string)$attributes['device_id'],
            tokenHash: (string)$attributes['token_hash'],
            expiresAt: (string)$attributes['expires_at'],
            revokedAt: $attributes['revoked_at'] !== null ? (string)$attributes['revoked_at'] : null,
            createdAt: (string)$attributes['created_at'],
        );
    }

    public function revokeRefreshToken(string $tokenHash): bool
    {
        $now = gmdate('Y-m-d H:i:s.v');

        return $this->refreshToken->newQuery()
            ->where('token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]) > 0;
    }

    /**
     * 仅 devices 唯一键冲突可视为预留竞争，仓储不得吞掉其他完整性错误。
     */
    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $previous = $exception->getPrevious();
        if (!$previous instanceof PDOException) {
            return false;
        }

        return $previous->getCode() === '23000'
            || (isset($previous->errorInfo[1]) && (int)$previous->errorInfo[1] === 1062);
    }
}
