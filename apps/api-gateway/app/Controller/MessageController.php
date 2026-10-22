<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Apps\ApiGateway\Grpc\MessageServiceClient;
use Apps\ApiGateway\Http\GatewayRequest;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;
use RuntimeException;
use Webman\Http\Response;

final readonly class MessageController
{
    public function __construct(private MessageServiceClient $messageClient) {}

    public function send(GatewayRequest $request, string $conversationId): Response
    {
        $this->actor($request);
        return ApiResponse::success($this->messageClient->send(
            $conversationId,
            trim((string)$request->post('client_message_id', '')),
            (int)$request->post('type', 1),
            (string)$request->post('content', ''),
        ), 201);
    }

    public function sync(GatewayRequest $request, string $conversationId): Response
    {
        $this->actor($request);
        return ApiResponse::success($this->messageClient->sync(
            $conversationId,
            trim((string)$request->get('after_seq', '0')),
            max(1, min(100, (int)$request->get('limit', 50))),
        ));
    }

    public function list(GatewayRequest $request, string $conversationId): Response
    {
        $this->actor($request);
        return ApiResponse::success($this->messageClient->list(
            $conversationId,
            max(1, min(100, (int)$request->get('page_size', 50))),
            trim((string)$request->get('cursor', '')),
        ));
    }

    private function actor(GatewayRequest $request): void
    {
        if (blank($request->authContext['user_id'])) {
            throw new RuntimeException(ErrorCode::AUTH_UNAUTHORIZED->message(), ErrorCode::AUTH_UNAUTHORIZED->value);
        }
    }
}
