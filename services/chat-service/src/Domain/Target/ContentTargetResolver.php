<?php

declare(strict_types=1);

namespace Services\ChatService\Domain\Target;

/**
 * Resolves an anonymous content target inside ContentService. The returned
 * owner and write-time display snapshot are trusted only after the RPC has
 * validated scope, reference, deletion and access rules.
 */
interface ContentTargetResolver
{
    /**
     * @return array{
     *   allowed:bool,
     *   deleted?:bool,
     *   scope_type?:string,
     *   scope_id?:string,
     *   anonymous_ref?:string,
     *   owner_user_id?:string,
     *   owner_persona_id?:string,
     *   identity_mode?:int,
     *   display_name?:?string,
     *   avatar_key?:?string,
     *   profile_accessible?:bool
     * }
     */
    public function resolveAnonymousTarget(
        string $requesterUserId,
        string $scopeType,
        string $scopeId,
        string $anonymousRef,
    ): array;
}
