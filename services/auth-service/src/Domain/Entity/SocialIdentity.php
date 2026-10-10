<?php

declare(strict_types=1);

namespace Services\AuthService\Domain\Entity;

use Services\AuthService\Domain\Auth\SocialProvider;

final readonly class SocialIdentity
{
    public const STATUS_PENDING = 0;
    /** @deprecated 为历史调用方保留旧名称。 */
    public const STATUS_PENDING_PHONE = self::STATUS_PENDING;
    public const STATUS_REGISTERING = 3;
    public const STATUS_ACTIVE = 1;
    public const STATUS_DISABLED = 2;

    public function __construct(
        public string $id,
        public SocialProvider $provider,
        public string $providerAppId,
        public string $identityNamespace,
        public string $subjectType,
        public string $subjectId,
        public string $openId,
        public ?string $unionId,
        public ?string $userId,
        public int $status,
        public ?string $boundAt,
        public ?string $lastLoginAt,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    public function isPendingPhone(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->userId === null;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->userId !== null;
    }

    public function isDisabled(): bool
    {
        return $this->status === self::STATUS_DISABLED;
    }
}
