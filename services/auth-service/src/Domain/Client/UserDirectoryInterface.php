<?php

declare(strict_types=1);

namespace Services\AuthService\Domain\Client;

interface UserDirectoryInterface
{
    public const STATUS_ACTIVE = 1;

    /**
     * @return array{user_id: string, is_new_user: bool}
     */
    public function findOrCreateByPhone(string $phone, string $registerChannel = 'default'): array;

    /**
     * @return array{user_id: string, status: int}
     */
    public function findById(string $userId): array;
}
