<?php

declare(strict_types=1);

namespace Services\UserService\Domain\Entity;

final readonly class Persona
{
    public function __construct(
        public string $id,
        public string $userId,
        public int $type,
        public string $nickname,
        public ?string $avatarKey = null,
        public ?string $bio = null,
        public int $status = 1,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'user_id'    => $this->userId,
            'type'       => $this->type,
            'nickname'   => $this->nickname,
            'avatar_key' => $this->avatarKey,
            'bio'        => $this->bio,
            'status'     => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
