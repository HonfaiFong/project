<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Apps\ApiGateway\Grpc\ChatServiceClient;
use Apps\ApiGateway\Http\GatewayRequest;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;
use Packages\Tracing\TraceContext;
use RuntimeException;
use Webman\Http\Response;

final readonly class ChatController
{
    public function __construct(private ChatServiceClient $chatClient) {}

    /**
     * @throws \Exception
     */
    public function create(GatewayRequest $request): Response
    {
        $this->actor($request);
        $fields = (array)$request->post();
        $target = (array)($fields['target'] ?? []);
        if (isset($target['anonymous']) && is_array($target['anonymous'])) {
            $target = $target['anonymous'];
        } elseif (isset($fields['anonymous_ref'])) {
            $target = [
                'scope_type' => (string)($fields['scope_type'] ?? ''),
                'scope_id' => (string)($fields['scope_id'] ?? ''),
                'anonymous_ref' => (string)$fields['anonymous_ref'],
            ];
        } else {
            $target = ['main_persona_id' => (string)($target['main_persona_id'] ?? $fields['main_persona_id'] ?? '0')];
        }
        return ApiResponse::success($this->chatClient->getOrCreate([
            'request_id' => (string)($fields['request_id'] ?? TraceContext::current()->requestId),
            'persona_id' => (string)($fields['persona_id'] ?? '0'),
            'identity_mode' => $fields['identity_mode'] ?? 1,
            'alias_display_name' => (string)($fields['alias_display_name'] ?? ''),
        ], $target), 201);
    }

    /**
     * @throws \Random\RandomException
     */
    public function get(GatewayRequest $request, string $conversationId): Response
    {
        $this->actor($request);
        return ApiResponse::success($this->chatClient->get($conversationId));
    }

    /**
     * @throws \Random\RandomException
     */
    public function list(GatewayRequest $request): Response
    {
        $this->actor($request);
        return ApiResponse::success($this->chatClient->list(max(1, min(100, (int)$request->get('page_size', 20))), trim((string)$request->get('cursor', ''))));
    }

    /**
     * @throws \Random\RandomException
     */
    public function checkCanMessage(GatewayRequest $request, string $recipientUserId): Response
    {
        $this->actor($request);
        return ApiResponse::success($this->chatClient->checkCanMessage($recipientUserId));
    }

    /**
     * @throws \Random\RandomException
     */
    public function markRead(GatewayRequest $request, string $conversationId): Response
    {
        $this->actor($request);
        return ApiResponse::success($this->chatClient->markRead($conversationId, (string)$request->post('seq', '0')));
    }

    private function actor(GatewayRequest $request): void
    {
        if (blank($request->authContext['user_id'])) {
            throw new RuntimeException(ErrorCode::AUTH_UNAUTHORIZED->message(), ErrorCode::AUTH_UNAUTHORIZED->value);
        }
    }
}
