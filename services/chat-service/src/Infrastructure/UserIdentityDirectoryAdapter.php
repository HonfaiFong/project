<?php

declare(strict_types=1);

namespace Services\ChatService\Infrastructure;

use Packages\GrpcCommon\BusinessActor;
use Packages\Identity\PersonaMode;
use Packages\Identity\PersonaOperation;
use Packages\Identity\UserIdentity;
use Packages\Identity\UserIdentityDirectory;
use Services\ChatService\Domain\Identity\UserIdentityClient;

/**
 * Adapts the shared UserIdentityDirectory to ChatService's private profile
 * shape. UserService remains the only authority for persona ownership and
 * account status; this class only maps its immutable result for the domain.
 */
final readonly class UserIdentityDirectoryAdapter implements UserIdentityClient
{
    public function __construct(private UserIdentityDirectory $directory)
    {
    }

    public function resolvePersonaMode(string $trustedUserId, int $mode, int $operation): array
    {
        $identity = $this->directory->resolve(
            $trustedUserId,
            PersonaMode::from($mode),
            PersonaOperation::from($operation),
            new BusinessActor($trustedUserId),
        );

        return ['persona' => $this->profile($identity)];
    }

    public function validatePersonaOwnership(string $trustedUserId, string $personaId, int $operation): array
    {
        $identity = $this->directory->validate(
            $trustedUserId,
            $personaId,
            PersonaOperation::from($operation),
            // The owner was resolved by the trusted ContentService target
            // RPC. Carry that owner explicitly so TraceContext from the
            // requesting account cannot leak into this peer validation hop.
            new BusinessActor($trustedUserId),
        );

        return ['persona' => $this->profile($identity)];
    }

    public function resolveMainPersona(string $trustedUserId, string $personaId, int $operation): array
    {
        $identity = $this->directory->main(
            $personaId,
            PersonaOperation::from($operation),
            new BusinessActor($trustedUserId),
        );

        return ['persona' => $this->profile($identity)];
    }

    /** @return array<string, mixed> */
    private function profile(UserIdentity $identity): array
    {
        return [
            'id' => $identity->personaId,
            'user_id' => $identity->userId,
            'type' => $identity->mode === PersonaMode::MAIN ? 1 : 2,
            'nickname' => $identity->displayName,
            'avatar_key' => $identity->avatarKey,
            'status' => 1,
            'is_internal_alias' => $identity->mode === PersonaMode::ALIAS,
            'identity_mode' => $identity->mode->value,
            'profile_accessible' => $identity->profileAccessible,
        ];
    }
}
