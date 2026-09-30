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

final class PdoSocialIdentityRepository implements SocialIdentityRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Snowflake $snowflake,
    ) {
    }

    public function findByExternalIdentity(ExternalIdentity $identity): ?SocialIdentity
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM social_identities
             WHERE provider = :provider
               AND identity_namespace = :identity_namespace
               AND subject_type = :subject_type
               AND subject_id = :subject_id
             LIMIT 1'
        );
        $stmt->execute([
            'provider'           => $identity->provider->value,
            'identity_namespace' => $identity->identityNamespace,
            'subject_type'       => $identity->subjectType(),
            'subject_id'         => $identity->subjectId(),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row && $identity->openId !== '') {
            // A provider may expose unionid only after an earlier openid-only login.
            // Reuse that row rather than creating a second pending identity when the
            // provider subject type changes.
            $fallback = $this->pdo->prepare(
                'SELECT * FROM social_identities
                 WHERE provider = :provider
                   AND provider_app_id = :provider_app_id
                   AND openid = :openid
                 LIMIT 1'
            );
            $fallback->execute([
                'provider'        => $identity->provider->value,
                'provider_app_id' => $identity->providerAppId,
                'openid'          => $identity->openId,
            ]);
            $row = $fallback->fetch(PDO::FETCH_ASSOC);
        }

        return $row ? $this->hydrate($row) : null;
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
        $stmt = $this->pdo->prepare('SELECT * FROM social_identities WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

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
