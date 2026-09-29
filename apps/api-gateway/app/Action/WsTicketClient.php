<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Action;

interface WsTicketClient
{
    /**
     * @return array{ticket: string, expires_at: int|string}
     */
    public function createWsTicket(
        string $userId,
        string $personaId,
        string $deviceId
    ): array;
}
