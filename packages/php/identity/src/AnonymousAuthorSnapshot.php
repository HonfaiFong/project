<?php

declare(strict_types=1);

namespace Packages\Identity;

/**
 * Immutable identity data captured when content or a message is written.
 *
 * The snapshot deliberately separates write-time display data from the
 * current persona row. A later profile edit therefore cannot rewrite the
 * author shown on historical content. For ALIAS, personaId is private
 * persistence data only; public projection uses anonymousRef instead.
 */
final readonly class AnonymousAuthorSnapshot
{
    public function __construct(
        public PersonaMode $mode,
        public ?string $personaId,
        public ?string $displayName,
        public ?string $avatarKey,
        public ?string $anonymousRef,
        public ?string $scopeType = null,
        public ?string $scopeId = null,
    ) {
        if ($this->mode === PersonaMode::MAIN && $this->personaId === null) {
            throw new \InvalidArgumentException('MAIN snapshots require a persona id');
        }

        if ($this->mode === PersonaMode::ALIAS && $this->anonymousRef !== null) {
            if (preg_match('/\Aanon_[A-Za-z0-9_-]{1,120}\z/', $this->anonymousRef) !== 1) {
                throw new \InvalidArgumentException('Alias snapshot reference is invalid');
            }
        }

        if ($this->mode === PersonaMode::ALIAS
            && ($this->personaId === null || $this->scopeType === null || $this->scopeId === null)
        ) {
            throw new \InvalidArgumentException('Alias snapshots require private identity and scope data');
        }

        if ($this->mode === PersonaMode::UNSPECIFIED
            && ($this->personaId !== null
                || $this->anonymousRef !== null
                || $this->scopeType !== null
                || $this->scopeId !== null)
        ) {
            throw new \InvalidArgumentException('Unknown snapshots cannot carry identity references');
        }
    }

    /**
     * @return array{
     *   identity_mode:int,
     *   persona_id:?string,
     *   display_name:?string,
     *   avatar_key:?string,
     *   anonymous_ref:?string,
     *   scope_type:?string,
     *   scope_id:?string
     * }
     */
    public function toArray(): array
    {
        return [
            'identity_mode' => $this->mode->value,
            'persona_id' => $this->personaId,
            'display_name' => $this->displayName,
            'avatar_key' => $this->avatarKey,
            'anonymous_ref' => $this->anonymousRef,
            'scope_type' => $this->scopeType,
            'scope_id' => $this->scopeId,
        ];
    }
}
