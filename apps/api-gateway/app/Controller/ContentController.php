<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Apps\ApiGateway\Grpc\ContentServiceClient;
use Apps\ApiGateway\Http\GatewayRequest;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;
use Contracts\Gen\Common\V1\IdentityMode;
use Packages\Tracing\TraceContext;
use Random\RandomException;
use RuntimeException;
use Webman\Http\Response;

final readonly class ContentController
{
    public function __construct(private ContentServiceClient $contentClient)
    {
    }

    public function createPost(GatewayRequest $request): Response
    {
        $this->requireActor($request);
        $fields = (array)$request->post();
        $fields['persona_id'] = $this->requestedPersonaId($request, $fields['persona_id'] ?? null);
        $result = $this->contentClient->createPost($this->writeFields($fields), $this->media($fields['media'] ?? []));

        return ApiResponse::success($result, 201);
    }

    public function getPost(string $postId): Response
    {
        return ApiResponse::success($this->contentClient->getPost($postId));
    }

    public function createComment(GatewayRequest $request, string $postId): Response
    {
        $this->requireActor($request);
        $fields = (array)$request->post();
        $fields['post_id'] = $postId;
        $fields['persona_id'] = $this->requestedPersonaId($request, $fields['persona_id'] ?? null);
        $fields = $this->writeFields($fields);
        $fields['parent_id'] = (string)($request->post('parent_id', '0'));
        $fields['reply_to_comment_id'] = (string)($request->post('reply_to_comment_id', '0'));

        return ApiResponse::success(['comment' => $this->contentClient->createComment($fields)['comment'] ?? []], 201);
    }

    public function listComments(GatewayRequest $request, string $postId): Response
    {
        $pageSize = max(1, min(100, (int)$request->get('page_size', 20)));
        $cursor = trim((string)$request->get('cursor', ''));
        $parentId = trim((string)$request->get('parent_id', ''));

        return ApiResponse::success($this->contentClient->listComments($postId, $parentId, $pageSize, $cursor));
    }

    public function deletePost(GatewayRequest $request, string $postId): Response
    {
        $this->requireActor($request);
        return ApiResponse::success($this->contentClient->deletePost($postId));
    }

    public function deleteComment(GatewayRequest $request, string $commentId): Response
    {
        $this->requireActor($request);
        return ApiResponse::success($this->contentClient->deleteComment($commentId));
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     * @throws RandomException
     */
    private function writeFields(array $fields): array
    {
        $mode = $this->identityMode($fields['identity_mode'] ?? $fields['mode'] ?? null);
        return [
            'request_id' => trim((string)($fields['request_id'] ?? TraceContext::current()->requestId)),
            'persona_id' => trim((string)($fields['persona_id'] ?? '0')),
            'type' => (int)($fields['type'] ?? 1),
            'content' => (string)($fields['content'] ?? ''),
            'visibility' => (int)($fields['visibility'] ?? 1),
            'emotion' => (string)($fields['emotion'] ?? ''),
            'identity_mode' => $mode,
            'alias_display_name' => (string)($fields['alias_display_name'] ?? ''),
            'post_id' => (string)($fields['post_id'] ?? '0'),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function media(mixed $media): array
    {
        if (!is_array($media)) {
            return [];
        }
        return array_values(array_filter($media, static fn (mixed $item): bool => is_array($item)));
    }

    private function identityMode(mixed $value): int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $mode = (int)$value;
        } else {
            $mode = match (strtolower(trim((string)$value))) {
                'main' => IdentityMode::IDENTITY_MODE_MAIN,
                'alias' => IdentityMode::IDENTITY_MODE_ALIAS,
                default => IdentityMode::IDENTITY_MODE_UNSPECIFIED,
            };
        }
        if (!in_array($mode, [IdentityMode::IDENTITY_MODE_MAIN, IdentityMode::IDENTITY_MODE_ALIAS], true)) {
            throw new RuntimeException('identity_mode is required', ErrorCode::PERSONA_FORBIDDEN->value);
        }
        return $mode;
    }

    /**
     * @param GatewayRequest $request
     * @param mixed $bodyPersonaId
     * @return string
     */
    private function requestedPersonaId(GatewayRequest $request, mixed $bodyPersonaId): string
    {
        $bodyPersonaId = trim((string)($bodyPersonaId ?? ''));
        if (!empty($bodyPersonaId)) {
            return $bodyPersonaId;
        }

        return trim((string)($request->authContext['requested_persona_id'] ?? '0'));
    }

    private function requireActor(GatewayRequest $request): void
    {
        if (blank($request->authContext['user_id'])) {
            throw new RuntimeException(ErrorCode::AUTH_UNAUTHORIZED->message(), ErrorCode::AUTH_UNAUTHORIZED->value);
        }
    }
}
