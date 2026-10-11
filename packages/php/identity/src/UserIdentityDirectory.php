<?php

declare(strict_types=1);

namespace Packages\Identity;

use Packages\GrpcCommon\BusinessActor;

/**
 * Service-to-service directory contract. Implementations must call
 * UserService with their own service token; callers never pass a gateway
 * Request or trust a persona id from an HTTP body.
 */
interface UserIdentityDirectory
{
    public function resolve(
        string $userId,
        PersonaMode $mode,
        PersonaOperation $operation,
        ?BusinessActor $actor = null,
    ): UserIdentity;

    public function validate(
        string $userId,
        string $personaId,
        PersonaOperation $operation,
        ?BusinessActor $actor = null,
    ): UserIdentity;

    public function main(
        string $personaId,
        PersonaOperation $operation,
        ?BusinessActor $actor = null,
    ): UserIdentity;
}
