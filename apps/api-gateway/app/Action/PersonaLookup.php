<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Action;

interface PersonaLookup
{
    /**
     * @return array{persona?: array<string, mixed>}
     */
    public function getPersona(string $personaId): array;
}
