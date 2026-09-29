<?php

declare(strict_types=1);

namespace Services\AuthService\Domain\Client;

interface UserDirectoryInterface
{
    /**
     * @return array{user_id: string, is_new_user: bool}
     */
    public function findOrCreateByPhone(string $phone): array;
}
