<?php

declare(strict_types=1);

namespace Services\SocialService\Domain\Service;

use Services\SocialService\Domain\Entity\MainPersona;

interface PersonaDirectoryInterface
{
    /**
     * Validate the authenticated account and requested persona for an
     * operation. Implementations must reject ALIAS and foreign personas.
     */
    public function assertOwnedMainPersona(string $userId, string $personaId, int $operation): MainPersona;

    /** Resolve a target persona, requiring an active MAIN and active account. */
    public function findActiveMainPersona(string $personaId): MainPersona;

    /** Require an active account without reading user_db from Social. */
    public function assertActiveUser(string $userId): void;

    /**
     * Resolve the authenticated account's MAIN persona for an account-level
     * operation such as BLOCK or UNBLOCK. The persona id is intentionally
     * returned only to the service boundary; callers must not derive it from
     * a client supplied body field.
     */
    public function assertActiveMainUser(string $userId, int $operation): MainPersona;
}
