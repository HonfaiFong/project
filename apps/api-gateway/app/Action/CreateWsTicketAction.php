<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Action;

use Contracts\Errors\ErrorCode;
use Packages\Tracing\TraceContext;
use RuntimeException;

final readonly class CreateWsTicketAction
{
    public function __construct(
        private WsTicketClient $authClient,
        private PersonaLookup  $userClient
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

        if (filled($requestedPersonaId) && filled($headerPersonaId) && $requestedPersonaId !== $headerPersonaId) {
            throw new RuntimeException('forbidden persona access', ErrorCode::PERSONA_FORBIDDEN->value);
        }

        $personaId = filled($requestedPersonaId) ? $requestedPersonaId : $headerPersonaId;

        // persona_id 是数字型 ID 字符串，'' 与 '0' 均不可用，恰与 empty() 对字符串的真值判定一致。
        if (empty($userId) || empty($personaId)) {
            throw new RuntimeException('unauthorized', ErrorCode::AUTH_UNAUTHORIZED->value);
        }

        $personaRes = $this->userClient->getPersona($personaId);
        $persona = $personaRes['persona'] ?? null;
        if (!is_array($persona) || (string)($persona['user_id'] ?? '') !== $userId) {
            throw new RuntimeException('forbidden persona access', ErrorCode::PERSONA_FORBIDDEN->value);
        }

        if ((int)($persona['status'] ?? 0) !== 1) {
            throw new RuntimeException('persona is inactive', ErrorCode::PERSONA_FORBIDDEN->value);
        }

        // 仅在 UserService 校验 persona 归属通过后，才把身份提升进可信 TraceContext。
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
