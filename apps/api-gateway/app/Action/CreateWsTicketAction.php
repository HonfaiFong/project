<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Action;

use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Contracts\Errors\ErrorCode;
use Packages\Tracing\TraceContext;
use RuntimeException;

final class CreateWsTicketAction
{
    public function __construct(
        private readonly AuthServiceClient $authClient,
        private readonly UserServiceClient $userClient
    ) {
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $authContext
     * @return array{ticket: string, expires_at: string}
     */
    public function execute(array $body, array $authContext): array
    {
        $userId = (string)($authContext['user_id'] ?? '');
        $deviceId = (string)($authContext['device_id'] ?? '');
        $requestedPersonaId = trim((string)($body['persona_id'] ?? ''));
        $headerPersonaId = trim((string)($authContext['requested_persona_id'] ?? ''));

        if ($requestedPersonaId !== '' && $headerPersonaId !== '' && $requestedPersonaId !== $headerPersonaId) {
            throw new RuntimeException('forbidden persona access', ErrorCode::PERSONA_FORBIDDEN->value);
        }

        $personaId = $requestedPersonaId !== '' ? $requestedPersonaId : $headerPersonaId;

        if ($userId === '' || $personaId === '' || $personaId === '0') {
            throw new RuntimeException('unauthorized', ErrorCode::AUTH_UNAUTHORIZED->value);
        }

        $personaRes = $this->userClient->getPersona($personaId);
        $persona = $personaRes['persona'] ?? null;
        if (!is_array($persona) || (string)($persona['user_id'] ?? '') !== (string)$userId) {
            throw new RuntimeException('forbidden persona access', ErrorCode::PERSONA_FORBIDDEN->value);
        }

        if ((int)($persona['status'] ?? 0) !== 1) {
            throw new RuntimeException('persona is inactive', ErrorCode::PERSONA_FORBIDDEN->value);
        }

        // Only after ownership is verified against UserService, elevate into trusted TraceContext.
        $currentTrace = TraceContext::current();
        TraceContext::setCurrent(TraceContext::create(
            requestId: $currentTrace->requestId,
            traceId: $currentTrace->traceId,
            userId: $userId,
            personaId: $personaId,
            deviceId: $deviceId ?: $currentTrace->deviceId,
            appVersion: $currentTrace->appVersion,
            traceparent: $currentTrace->traceparent
        ));

        $res = $this->authClient->createWsTicket($userId, $personaId, $deviceId);

        return [
            'ticket'     => (string)$res['ticket'],
            'expires_at' => (string)$res['expires_at'],
        ];
    }
}
