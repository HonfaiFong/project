<?php

declare(strict_types=1);

namespace Services\UserService\Domain\Entity;

final readonly class User
{
    public const STATUS_ACTIVE = 1;

    public function __construct(
        public string $id,
        public int $status,
        public ?string $phone = null,
        public ?string $email = null,
        public string $registerChannel = 'default',
        public int $riskLevel = 0,
        public ?string $lastLoginAt = null,
        public ?string $lastActiveAt = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'status'           => $this->status,
            'phone'            => $this->phone,
            'email'            => $this->email,
            'register_channel' => $this->registerChannel,
            'risk_level'       => $this->riskLevel,
            'last_login_at'    => $this->lastLoginAt,
            'last_active_at'   => $this->lastActiveAt,
            'created_at'       => $this->createdAt,
            'updated_at'       => $this->updatedAt,
        ];
    }
}
