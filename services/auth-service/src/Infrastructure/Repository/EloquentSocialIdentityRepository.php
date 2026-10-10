<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Repository;

use Contracts\Errors\ErrorCode;
use Illuminate\Database\QueryException;
use Packages\Database\EloquentConnection;
use Packages\IdGenerator\Snowflake;
use PDO;
use PDOException;
use RuntimeException;
use Services\AuthService\Domain\Auth\ExternalIdentity;
use Services\AuthService\Domain\Auth\SocialProvider;
use Services\AuthService\Domain\Entity\SocialIdentity;
use Services\AuthService\Domain\Repository\SocialIdentityRepositoryInterface;
use Services\AuthService\Infrastructure\Model\SocialIdentityAlias as SocialIdentityAliasModel;
use Services\AuthService\Infrastructure\Model\SocialIdentity as SocialIdentityModel;
use Throwable;
use Error;

final readonly class EloquentSocialIdentityRepository implements SocialIdentityRepositoryInterface
{
    private SocialIdentityModel $identity;
    private SocialIdentityAliasModel $alias;

    public function __construct(
        private PDO $pdo,
        private Snowflake $snowflake,
    ) {
        $this->identity = EloquentConnection::model(SocialIdentityModel::class, $pdo);
        $this->alias = EloquentConnection::model(SocialIdentityAliasModel::class, $pdo);
    }

    /** @throws Throwable If identity reconciliation cannot complete atomically. */
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
        // The transaction stays on the injected PDO: an outer caller may have
        // started it outside the Eloquent Connection, whose own level counter
        // would then be out of sync.
        $ownsTransaction = !$this->pdo->inTransaction();
        $transactionStarted = false;
        if ($ownsTransaction) {
            $this->identity->getConnection()->beginTransaction();
            $transactionStarted = true;
        }

        try {
            $unionRow = $this->findRowBySubject($identity, lock: true);
            $openIdRow = $this->findRowByProviderOpenId($identity, lock: true);
            $row = $this->reconcileUnionIdentity($identity, $unionRow, $openIdRow);
            // A new app may resolve an existing unionid without an openid row.
            // Keep that verified mapping even when authentication is disabled.
            if ($row !== null && ((string)$row['provider_app_id'] !== $identity->providerAppId
                || (string)$row['openid'] !== $identity->openId)) {
                $this->rememberOpenIdAlias(array_replace($row, [
                    'provider_app_id' => $identity->providerAppId,
                    'openid' => $identity->openId,
                ]), (string)$row['id']);
            }

            if ($ownsTransaction) {
                $this->identity->getConnection()->commit();
            }

            return $row ? $this->hydrate($row) : null;
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $this->identity->getConnection()->rollBack();
            }

            throw $e;
        }
    }

    /** @throws Throwable If identity reservation or reconciliation fails. */
    public function reserve(ExternalIdentity $identity): SocialIdentity
    {
        $existing = $this->findByExternalIdentity($identity);
        if ($existing !== null) {
            return $existing;
        }

        $id = $this->snowflake->nextId();
        $now = gmdate('Y-m-d H:i:s.v');
        try {
            $this->identity->newQuery()->create([
                'id' => $id,
                'user_id' => null,
                'provider' => $identity->provider->value,
                'provider_app_id' => $identity->providerAppId,
                'identity_namespace' => $identity->identityNamespace,
                'subject_type' => $identity->subjectType(),
                'subject_id' => $identity->subjectId(),
                'openid' => $identity->openId,
                'unionid' => $identity->unionId,
                'status' => SocialIdentity::STATUS_PENDING,
                'bound_at' => null,
                'last_login_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException $e) {
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

    public function beginRegistration(string $identityId): SocialIdentity
    {
        $this->identity->newQuery()
            ->whereKey($identityId)
            ->where('status', SocialIdentity::STATUS_PENDING)
            ->whereNull('user_id')
            ->update([
                'status' => SocialIdentity::STATUS_REGISTERING,
                'updated_at' => gmdate('Y-m-d H:i:s.v'),
            ]);
        $identity = $this->findById($identityId);
        if ($identity === null || (!$identity->isActive()
            && !($identity->status === SocialIdentity::STATUS_REGISTERING && $identity->userId === null))) {
            throw $this->bindConflict();
        }

        return $identity;
    }

    public function completeRegistration(string $identityId, string $userId): SocialIdentity
    {
        return $this->bind($identityId, $userId, SocialIdentity::STATUS_REGISTERING);
    }

    public function bindToUser(string $identityId, string $userId): SocialIdentity
    {
        return $this->bind($identityId, $userId, SocialIdentity::STATUS_PENDING);
    }

    private function bind(string $identityId, string $userId, int $expectedStatus): SocialIdentity
    {
        $now = gmdate('Y-m-d H:i:s.v');

        try {
            $updated = $this->identity->newQuery()
                ->whereKey($identityId)
                ->where('status', $expectedStatus)
                ->whereNull('user_id')
                ->update([
                    'user_id' => $userId,
                    'status' => SocialIdentity::STATUS_ACTIVE,
                    'bound_at' => $now,
                    'updated_at' => $now,
                ]);
        } catch (QueryException $e) {
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
        if ($updated === 1 && $identity !== null) {
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
        $this->identity->newQuery()
            ->whereKey($identityId)
            ->where('status', SocialIdentity::STATUS_ACTIVE)
            ->whereNotNull('user_id')
            ->update([
                'last_login_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /** @return array<string, mixed>|null */
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

        $unionRegistering = (int)$unionRow['status'] === SocialIdentity::STATUS_REGISTERING;
        $openIdRegistering = (int)$openIdRow['status'] === SocialIdentity::STATUS_REGISTERING;
        if ($unionRegistering || $openIdRegistering) {
            $intent = $unionRegistering ? $unionRow : $openIdRow;
            $pending = $unionRegistering ? $openIdRow : $unionRow;
            if ($pending['user_id'] !== null || (int)$pending['status'] !== SocialIdentity::STATUS_PENDING) {
                throw $this->bindConflict();
            }
            // The registering row owns the stable RPC key, even when the other
            // row acquired a unionid first. Preserve it across OAuth upgrades.
            $this->rememberOpenIdAlias($intent, (string)$intent['id']);
            $this->rememberOpenIdAlias($pending, (string)$intent['id']);
            $this->redirectAliases((string)$pending['id'], (string)$intent['id']);
            $this->identity->newQuery()->whereKey($pending['id'])->delete();

            return $unionRegistering ? $intent : $this->promoteOpenIdRow($identity, $intent);
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
        $this->rememberOpenIdAlias($unionRow, (string)$unionRow['id']);
        $this->rememberOpenIdAlias($openIdRow, (string)$unionRow['id']);
        $this->redirectAliases((string)$openIdRow['id'], (string)$unionRow['id']);
        $this->identity->newQuery()->whereKey($openIdRow['id'])->delete();

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
            ? SocialIdentity::STATUS_PENDING
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
            $this->identity->newQuery()->whereKey($unionRow['id'])->update([
                'user_id' => $userId,
                'status' => $status,
                'bound_at' => $boundAt,
                'updated_at' => $now,
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

        try {
            $updated = $this->identity->newQuery()
                ->whereKey($openIdRow['id'])
                ->where('subject_type', 'openid')
                ->where('subject_id', $identity->openId)
                ->update([
                    'subject_type' => 'unionid',
                    'subject_id' => $identity->unionId,
                    'unionid' => $identity->unionId,
                    'updated_at' => $now,
                ]);
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                throw $this->bindConflict($e);
            }

            throw $e;
        }

        $promoted = $this->findRowById((string)$openIdRow['id']);
        if ($updated !== 1 || $promoted === null) {
            throw $this->bindConflict();
        }

        return $promoted;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRowBySubject(ExternalIdentity $identity, bool $lock = false): ?array
    {
        $query = $this->identity->newQuery();
        $query->where('provider', $identity->provider->value)
            ->where('identity_namespace', $identity->identityNamespace)
            ->where('subject_type', $identity->subjectType())
            ->where('subject_id', $identity->subjectId());
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        return $row?->getAttributes();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRowByProviderOpenId(ExternalIdentity $identity, bool $lock = false): ?array
    {
        if ($identity->openId === '') {
            return null;
        }

        $query = $this->identity->newQuery();
        $query->where('provider', $identity->provider->value)
            ->where('provider_app_id', $identity->providerAppId)
            ->where('openid', $identity->openId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();

        if ($row !== null) {
            return $row->getAttributes();
        }

        $query = $this->identity->newQuery();
        $query->from('social_identity_aliases as alias')
            ->select('identity.*')
            ->join('social_identities as identity', 'identity.id', '=', 'alias.social_identity_id')
            ->where('alias.provider', $identity->provider->value)
            ->where('alias.provider_app_id', $identity->providerAppId)
            ->where('alias.openid', $identity->openId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $aliased = $query->first();

        return $aliased?->getAttributes();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRowById(string $id): ?array
    {
        $query = $this->identity->newQuery();
        $query->whereKey($id);
        $row = $query->first();

        return $row?->getAttributes();
    }

    /** @param array<string, mixed> $row */
    private function rememberOpenIdAlias(array $row, string $canonicalId): void
    {
        // Atomic insert that never overwrites an alias already owned by a
        // different identity: the existing row wins and the update below is a
        // no-op assigned to the unique key itself.
        $this->alias->newQuery()->upsert(
            [
                [
                    'provider' => $row['provider'],
                    'provider_app_id' => $row['provider_app_id'],
                    'openid' => $row['openid'],
                    'social_identity_id' => $canonicalId,
                ],
            ],
            ['provider', 'provider_app_id', 'openid'],
            ['provider'],
        );

        $existingId = (string)$this->alias->newQuery()
            ->where('provider', $row['provider'])
            ->where('provider_app_id', $row['provider_app_id'])
            ->where('openid', $row['openid'])
            ->lockForUpdate()
            ->value('social_identity_id');
        if ($existingId !== $canonicalId && $existingId !== (string)$row['id']) {
            throw $this->bindConflict();
        }
    }

    private function redirectAliases(string $oldId, string $canonicalId): void
    {
        $this->alias->newQuery()
            ->where('social_identity_id', $oldId)
            ->update(['social_identity_id' => $canonicalId]);
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
        } catch (Error $e) {
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

    /**
     * Matches the previous PDO handling: SQLSTATE 23000 or MySQL error 1062.
     * Laravel wraps driver errors in QueryException, so unwrap the PDO cause.
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
