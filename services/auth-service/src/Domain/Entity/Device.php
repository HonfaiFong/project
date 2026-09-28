<?php

declare(strict_types=1);

namespace Services\AuthService\Domain\Entity;

final readonly class Device
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $deviceUuid,
        public string $platform,
        public string $appVersion,
        public ?string $pushToken = null,
        public ?string $lastIp = null,
        public ?string $lastActiveAt = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
    }
}
