<?php

declare(strict_types=1);

namespace Services\UserService\Domain\Repository;

use Services\UserService\Domain\Entity\User;

interface UserRepositoryInterface
{
    public function findById(string $id): ?User;

    public function findByIdForUpdate(string $id): ?User;

    public function findByPhone(string $phone): ?User;

    /**
     * 查找与调用方注册键绑定的用户，社交登录传入 'social:' + social_identities.id。被封禁用户仍须可查，避免重复注册
     * 通过创建新账户绕过封禁。
     */
    public function findBySocialRegistrationKey(string $registrationKey): ?User;

    /**
     * 检查注册键是否已占用，包括软删除账户。唯一索引继续保留这些键，以便注册返回明确错误，而非内部错误。
     */
    public function socialRegistrationKeyExists(string $registrationKey): bool;

    public function findByEmail(string $email): ?User;

    /**
     * @param array<string> $ids
     * @return array<User>
     */
    public function batchFindByIds(array $ids): array;

    public function create(
        string $id,
        ?string $phone,
        ?string $email,
        string $registerChannel = 'default',
        int $riskLevel = 0,
        ?string $socialRegistrationKey = null
    ): User;

    public function update(string $id, ?string $phone, ?string $email): ?User;

    public function updateLastLogin(string $id): void;
}
