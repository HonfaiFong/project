<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Action;

use Exception;
use Contracts\Errors\ErrorCode;
use Packages\Tracing\TraceContext;
use RuntimeException;
use Throwable;

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
     * @throws Exception
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

        // UserService performs the authoritative account, ownership and
        // current-status check. The client-provided persona id is not trusted
        // until this RPC succeeds.
        $personaRes = $this->userClient->validatePersonaOwnership($userId, $personaId);
        $persona = $personaRes['persona'] ?? null;
        if (!is_array($persona) || (string)($persona['id'] ?? '') !== $personaId) {
            throw new RuntimeException('forbidden persona access', ErrorCode::PERSONA_FORBIDDEN->value);
        }

        if ((int)($persona['status'] ?? 0) !== 1) {
            throw new RuntimeException('persona is inactive', ErrorCode::PERSONA_FORBIDDEN->value);
        }

        // The validated persona must be present while AuthService issues the
        // ticket so its audit/metadata path receives the same trusted identity.
        // Restore the previous context if issuance fails because this worker
        // may serve another request after the exception is handled.
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

        try {
            $res = $this->authClient->createWsTicket($userId, $personaId, $deviceId);

            return [
                'ticket'     => (string)$res['ticket'],
                'expires_at' => (string)$res['expires_at'],
            ];
        } catch (Throwable $exception) {
            TraceContext::setCurrent($currentTrace);
            throw $exception;
        }
    }
}
