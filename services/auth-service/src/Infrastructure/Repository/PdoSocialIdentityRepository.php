<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Repository;

use Contracts\Errors\ErrorCode;
use PDO;
use PDOException;
use Packages\IdGenerator\Snowflake;
use RuntimeException;
use Services\AuthService\Domain\Auth\ExternalIdentity;
use Services\AuthService\Domain\Auth\SocialProvider;
use Services\AuthService\Domain\Entity\SocialIdentity;
use Services\AuthService\Domain\Repository\SocialIdentityRepositoryInterface;
use Throwable;

final class PdoSocialIdentityRepository implements SocialIdentityRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Snowflake $snowflake,
    ) {
    }

    public function findByExternalIdentity(ExternalIdentity $identity): ?SocialIdentity
    {
        if ($identity->unionId === null || $identity->unionId === '') {
            $row = $this->findRowBySubject($identity);
            if ($row === null) {
                $row = $this->findRowByProviderOpenId($identity);
            }

            return $row ? $this->hydrate($row) : null;
        }

        // Union IDs can arrive after an openid-only row was created. Both rows
        // must be read and reconciled while locked; otherwise two app requests
        // can promote or bind different rows for the same provider identity.
        $ownsTransaction = !$this->pdo->inTransaction();
        $transactionStarted = false;
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
            $transactionStarted = true;
        }

        try {
            $unionRow = $this->findRowBySubject($identity, lock: true);
            $openIdRow = $this->findRowByProviderOpenId($identity, lock: true);
            $row = $this->reconcileUnionIdentity($identity, $unionRow, $openIdRow);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $row ? $this->hydrate($row) : null;
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function reserve(ExternalIdentity $identity): SocialIdentity
    {
        $existing = $this->findByExternalIdentity($identity);
        if ($existing !== null) {
            return $existing;
        }

        $id = $this->snowflake->nextId();
        $now = gmdate('Y-m-d H:i:s.v');
        $stmt = $this->pdo->prepare(
            'INSERT INTO social_identities
             (id, user_id, provider, provider_app_id, identity_namespace, subject_type, subject_id,
              openid, unionid, status, bound_at, last_login_at, created_at, updated_at)
             VALUES
             (:id, NULL, :provider, :provider_app_id, :identity_namespace, :subject_type, :subject_id,
              :openid, :unionid, :status, NULL, NULL, :created_at, :updated_at)'
        );

        try {
            $stmt->execute([
                'id'                 => $id,
                'provider'           => $identity->provider->value,
                'provider_app_id'    => $identity->providerAppId,
                'identity_namespace' => $identity->identityNamespace,
                'subject_type'       => $identity->subjectType(),
                'subject_id'         => $identity->subjectId(),
                'openid'             => $identity->openId,
                'unionid'            => $identity->unionId,
                'status'             => SocialIdentity::STATUS_PENDING_PHONE,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        } catch (PDOException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                $winner = $this->findByExternalIdentity($identity);
                if ($winner !== null) {
                    return $winner;
                }
            }

            throw new RuntimeException(
                ErrorCode::INTERNAL_SERVER_ERROR->message(),
                ErrorCode::INTERNAL_SERVER_ERROR->value,
                $e
            );
        }

        $reserved = $this->findById($id);
        if ($reserved === null) {
            throw new RuntimeException(
                ErrorCode::INTERNAL_SERVER_ERROR->message(),
                ErrorCode::INTERNAL_SERVER_ERROR->value
            );
        }

        return $reserved;
    }

    public function findById(string $id): ?SocialIdentity
    {
        $row = $this->findRowById($id);

        return $row ? $this->hydrate($row) : null;
    }

    public function bindToUser(string $identityId, string $userId): SocialIdentity
    {
        $now = gmdate('Y-m-d H:i:s.v');
        $stmt = $this->pdo->prepare(
            'UPDATE social_identities
             SET user_id = :user_id,
                 status = :active,
                 bound_at = :bound_at,
                 updated_at = :updated_at
             WHERE id = :id
               AND status = :pending
               AND user_id IS NULL'
        );

        try {
            $stmt->execute([
                'user_id'    => $userId,
                'active'     => SocialIdentity::STATUS_ACTIVE,
                'bound_at'   => $now,
                'updated_at' => $now,
                'id'         => $identityId,
                'pending'    => SocialIdentity::STATUS_PENDING_PHONE,
            ]);
        } catch (PDOException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw new RuntimeException(
                    ErrorCode::AUTH_SOCIAL_PROVIDER_ALREADY_BOUND->message(),
                    ErrorCode::AUTH_SOCIAL_PROVIDER_ALREADY_BOUND->value,
                    $e
                );
            }

            throw $e;
        }

        $identity = $this->findById($identityId);
        if ($stmt->rowCount() === 1 && $identity !== null) {
            return $identity;
        }

        if ($identity === null) {
            throw new RuntimeException(
                ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->message(),
                ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->value
            );
        }

        if ($identity->isActive() && $identity->userId === $userId) {
            return $identity;
        }

        throw new RuntimeException(
            ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->message(),
            ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->value
        );
    }

    public function touchLastLogin(string $identityId): void
    {
        $now = gmdate('Y-m-d H:i:s.v');
        $stmt = $this->pdo->prepare(
            'UPDATE social_identities
             SET last_login_at = :last_login_at, updated_at = :updated_at
             WHERE id = :id AND status = :active AND user_id IS NOT NULL'
        );
        $stmt->execute([
            'last_login_at' => $now,
            'updated_at'    => $now,
            'id'            => $identityId,
            'active'        => SocialIdentity::STATUS_ACTIVE,
        ]);
    }

    /**
     * @param array<string, mixed>|null $unionRow
     * @param array<string, mixed>|null $openIdRow
     * @return array<string, mixed>|null
     */
    private function reconcileUnionIdentity(
        ExternalIdentity $identity,
        ?array $unionRow,
        ?array $openIdRow,
    ): ?array {
        if ($unionRow === null && $openIdRow === null) {
            return null;
        }

        if ($unionRow === null) {
            if ((string)$openIdRow['subject_type'] === 'unionid') {
                if ((string)$openIdRow['subject_id'] !== $identity->unionId) {
                    throw $this->bindConflict();
                }

                return $openIdRow;
            }

            return $this->promoteOpenIdRow($identity, $openIdRow);
        }

        if ($openIdRow === null || (string)$unionRow['id'] === (string)$openIdRow['id']) {
            return $unionRow;
        }

        // A single openid must not be allowed to move between union IDs, even
        // when the rows currently happen to point at the same local user.
        if ((string)$openIdRow['subject_type'] === 'unionid'
            && (string)$openIdRow['subject_id'] !== $identity->unionId
        ) {
            throw $this->bindConflict();
        }

        $unionUserId = $unionRow['user_id'] === null ? null : (string)$unionRow['user_id'];
        $openIdUserId = $openIdRow['user_id'] === null ? null : (string)$openIdRow['user_id'];
        if ($unionUserId !== null && $openIdUserId !== null && $unionUserId !== $openIdUserId) {
            throw $this->bindConflict();
        }

        $canonicalUserId = $unionUserId ?? $openIdUserId;
        $canonicalStatus = $this->reconciledStatus($unionRow, $openIdRow, $canonicalUserId);
        $canonicalBoundAt = $unionRow['bound_at'];
        if ($canonicalUserId !== null && $canonicalBoundAt === null) {
            $canonicalBoundAt = $openIdRow['bound_at'] ?? gmdate('Y-m-d H:i:s.v');
        }

        // Delete first when the openid row already owns the same user. The
        // production unique(user_id, provider) key then permits the canonical
        // unionid row to inherit that user atomically.
        $delete = $this->pdo->prepare('DELETE FROM social_identities WHERE id = :id');
        $delete->execute(['id' => $openIdRow['id']]);

        return $this->updateCanonicalUnionRow(
            $unionRow,
            $canonicalUserId,
            $canonicalStatus,
            $canonicalBoundAt,
        );
    }

    /**
     * @param array<string, mixed> $unionRow
     * @param array<string, mixed> $openIdRow
     */
    private function reconciledStatus(array $unionRow, array $openIdRow, ?string $userId): int
    {
        if ((int)$unionRow['status'] === SocialIdentity::STATUS_DISABLED
            || (int)$openIdRow['status'] === SocialIdentity::STATUS_DISABLED
        ) {
            return SocialIdentity::STATUS_DISABLED;
        }

        return $userId === null
            ? SocialIdentity::STATUS_PENDING_PHONE
            : SocialIdentity::STATUS_ACTIVE;
    }

    /**
     * @param array<string, mixed> $unionRow
     * @return array<string, mixed>
     */
    private function updateCanonicalUnionRow(
        array $unionRow,
        ?string $userId,
        int $status,
        ?string $boundAt,
    ): array {
        $currentUserId = $unionRow['user_id'] === null ? null : (string)$unionRow['user_id'];
        if ($currentUserId !== $userId
            || (int)$unionRow['status'] !== $status
            || $unionRow['bound_at'] !== $boundAt
        ) {
            $now = gmdate('Y-m-d H:i:s.v');
            $stmt = $this->pdo->prepare(
                'UPDATE social_identities
                 SET user_id = :user_id,
                     status = :status,
                     bound_at = :bound_at,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                'user_id'    => $userId,
                'status'     => $status,
                'bound_at'   => $boundAt,
                'updated_at' => $now,
                'id'         => $unionRow['id'],
            ]);
        }

        $canonical = $this->findRowById((string)$unionRow['id']);
        if ($canonical === null) {
            throw $this->bindConflict();
        }

        return $canonical;
    }

    /**
     * @param array<string, mixed> $openIdRow
     * @return array<string, mixed>
     */
    private function promoteOpenIdRow(ExternalIdentity $identity, array $openIdRow): array
    {
        $now = gmdate('Y-m-d H:i:s.v');
        $stmt = $this->pdo->prepare(
            'UPDATE social_identities
             SET subject_type = :subject_type,
                 subject_id = :subject_id,
                 unionid = :unionid,
                 updated_at = :updated_at
             WHERE id = :id
               AND subject_type = :openid_type
               AND subject_id = :openid'
        );

        try {
            $stmt->execute([
                'subject_type' => 'unionid',
                'subject_id'   => $identity->unionId,
                'unionid'      => $identity->unionId,
                'updated_at'   => $now,
                'id'           => $openIdRow['id'],
                'openid_type'  => 'openid',
                'openid'       => $identity->openId,
            ]);
        } catch (PDOException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw $this->bindConflict($e);
            }

            throw $e;
        }

        $promoted = $this->findRowById((string)$openIdRow['id']);
        if ($stmt->rowCount() !== 1 || $promoted === null) {
            throw $this->bindConflict();
        }

        return $promoted;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRowBySubject(ExternalIdentity $identity, bool $lock = false): ?array
    {
        $sql =
            'SELECT * FROM social_identities
             WHERE provider = :provider
               AND identity_namespace = :identity_namespace
               AND subject_type = :subject_type
               AND subject_id = :subject_id
             LIMIT 1';
        $stmt = $this->pdo->prepare($this->withRowLock($sql, $lock));
        $stmt->execute([
            'provider'           => $identity->provider->value,
            'identity_namespace' => $identity->identityNamespace,
            'subject_type'       => $identity->subjectType(),
            'subject_id'         => $identity->subjectId(),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRowByProviderOpenId(ExternalIdentity $identity, bool $lock = false): ?array
    {
        if ($identity->openId === '') {
            return null;
        }

        $sql =
            'SELECT * FROM social_identities
             WHERE provider = :provider
               AND provider_app_id = :provider_app_id
               AND openid = :openid
             LIMIT 1';
        $stmt = $this->pdo->prepare($this->withRowLock($sql, $lock));
        $stmt->execute([
            'provider'        => $identity->provider->value,
            'provider_app_id' => $identity->providerAppId,
            'openid'          => $identity->openId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRowById(string $id, bool $lock = false): ?array
    {
        $sql = 'SELECT * FROM social_identities WHERE id = :id LIMIT 1';
        $stmt = $this->pdo->prepare($this->withRowLock($sql, $lock));
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function withRowLock(string $sql, bool $lock): string
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($lock && in_array($driver, ['mysql', 'pgsql'], true)) {
            return $sql . ' FOR UPDATE';
        }

        return $sql;
    }

    private function bindConflict(?Throwable $previous = null): RuntimeException
    {
        return new RuntimeException(
            ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->message(),
            ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->value,
            $previous
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SocialIdentity
    {
        try {
            $provider = SocialProvider::from((string)$row['provider']);
        } catch (\ValueError $e) {
            throw new RuntimeException(
                ErrorCode::INTERNAL_SERVER_ERROR->message(),
                ErrorCode::INTERNAL_SERVER_ERROR->value,
                $e
            );
        }

        return new SocialIdentity(
            id: (string)$row['id'],
            provider: $provider,
            providerAppId: (string)$row['provider_app_id'],
            identityNamespace: (string)$row['identity_namespace'],
            subjectType: (string)$row['subject_type'],
            subjectId: (string)$row['subject_id'],
            openId: (string)$row['openid'],
            unionId: $row['unionid'] !== null ? (string)$row['unionid'] : null,
            userId: $row['user_id'] !== null ? (string)$row['user_id'] : null,
            status: (int)$row['status'],
            boundAt: $row['bound_at'] !== null ? (string)$row['bound_at'] : null,
            lastLoginAt: $row['last_login_at'] !== null ? (string)$row['last_login_at'] : null,
            createdAt: (string)$row['created_at'],
            updatedAt: (string)$row['updated_at'],
        );
    }

    private function isUniqueConstraintViolation(PDOException $exception): bool
    {
        return $exception->getCode() === '23000'
            || (isset($exception->errorInfo[1]) && (int)$exception->errorInfo[1] === 1062);
    }
}
