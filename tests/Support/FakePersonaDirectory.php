<?php

declare(strict_types=1);

namespace Tests\Support;

use Services\SocialService\Domain\Entity\MainPersona;
use Services\SocialService\Domain\Service\PersonaDirectoryInterface;

/** Social 并发/集成测试使用的真身目录替身：只做最小授权，不访问 UserService。 */
final class FakePersonaDirectory implements PersonaDirectoryInterface
{
    public function assertOwnedMainPersona(string $userId, string $personaId, int $operation): MainPersona
    {
        return new MainPersona($personaId, $userId);
    }

    public function findActiveMainPersona(string $personaId): MainPersona
    {
        return new MainPersona($personaId, '20');
    }

    public function assertActiveUser(string $userId): void
    {
    }

    public function assertActiveMainUser(string $userId, int $operation): MainPersona
    {
        return new MainPersona($userId . '-main', $userId);
    }
}
