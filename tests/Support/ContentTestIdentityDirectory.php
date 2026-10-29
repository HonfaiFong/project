<?php

declare(strict_types=1);

namespace Tests\Support;

use Packages\GrpcCommon\BusinessActor;
use Packages\Identity\PersonaMode;
use Packages\Identity\PersonaOperation;
use Packages\Identity\UserIdentity;
use Packages\Identity\UserIdentityDirectory;
use RuntimeException;

/** Content 集成测试使用的身份目录替身：按账号推导 MAIN/ALIAS Persona，不访问 UserService。 */
final class ContentTestIdentityDirectory implements UserIdentityDirectory
{
    public function resolve(string $userId, PersonaMode $mode, PersonaOperation $operation, ?BusinessActor $actor = null): UserIdentity
    {
        if ($actor === null || $actor->userId !== $userId) {
            throw new RuntimeException('actor mismatch');
        }
        $personaId = $mode === PersonaMode::MAIN ? (string)((int)$userId * 10 + 10) : (string)((int)$userId * 10 + 1);

        return new UserIdentity($userId, $personaId, $mode, $mode === PersonaMode::MAIN ? 'Main ' . $userId : 'Internal alias', null, $mode === PersonaMode::MAIN);
    }

    public function validate(string $userId, string $personaId, PersonaOperation $operation, ?BusinessActor $actor = null): UserIdentity
    {
        throw new RuntimeException('not used');
    }

    public function main(string $personaId, PersonaOperation $operation, ?BusinessActor $actor = null): UserIdentity
    {
        throw new RuntimeException('not used');
    }
}
