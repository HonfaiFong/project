<?php

declare(strict_types=1);

namespace Packages\Identity;

/** The private identity returned by UserService for an authorized operation. */
final readonly class UserIdentity
{
    public function __construct(
        public string $userId,
        public string $personaId,
        public PersonaMode $mode,
        public string $displayName,
        public ?string $avatarKey,
        public bool $profileAccessible,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'persona_id' => $this->personaId,
            'identity_mode' => $this->mode->value,
            'display_name' => $this->displayName,
            'avatar_key' => $this->avatarKey,
            'profile_accessible' => $this->profileAccessible,
        ];
    }
}
