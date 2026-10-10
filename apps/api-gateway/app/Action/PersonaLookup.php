<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Action;

interface PersonaLookup
{
    /**
     * @return array{persona?: array<string, mixed>}
     */
    public function getPersona(string $personaId): array;

    /**
     * @return array{persona?: array<string, mixed>}
     */
    public function validatePersonaOwnership(string $userId, string $personaId, int $operation = 0): array;

    /** @return array{persona?: array<string, mixed>} */
    public function resolvePersonaMode(string $userId, int $mode, int $operation): array;
}
