<?php

declare(strict_types=1);

namespace Services\ChatService\Domain\Identity;

/**
 * The service boundary used by ChatService. Implementations must call the
 * UserService RPC; they must never infer account ownership from request data.
 *
 * @phpstan-type PersonaProfile array{id:string,user_id:string,type:int,nickname:string,avatar_key:?string,status:int,is_internal_alias:bool,identity_mode:string,profile_accessible:bool}
 */
interface UserIdentityClient
{
    /** @return array<string, mixed> */
    public function resolvePersonaMode(string $trustedUserId, int $mode, int $operation): array;

    /** @return array<string, mixed> */
    public function validatePersonaOwnership(string $trustedUserId, string $personaId, int $operation): array;

    /** @return array<string, mixed> */
    public function resolveMainPersona(string $trustedUserId, string $personaId, int $operation): array;
}
