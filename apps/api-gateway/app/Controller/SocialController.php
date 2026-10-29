<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Controller;

use Apps\ApiGateway\Grpc\SocialServiceClient;
use Apps\ApiGateway\Http\GatewayRequest;
use Apps\ApiGateway\Response\ApiResponse;
use Contracts\Errors\ErrorCode;
use Contracts\Gen\Common\V1\IdentityMode;
use RuntimeException;
use Webman\Http\Response;

final readonly class SocialController
{
    public function __construct(private SocialServiceClient $socialClient) {}

    public function likePost(GatewayRequest $request, string $postId): Response { return $this->like($request, 1, $postId); }
    public function likeComment(GatewayRequest $request, string $commentId): Response { return $this->like($request, 2, $commentId); }
    public function unlikePost(GatewayRequest $request, string $postId): Response { return $this->unlike($request, 1, $postId); }
    public function unlikeComment(GatewayRequest $request, string $commentId): Response { return $this->unlike($request, 2, $commentId); }

    /**
     * @throws \Random\RandomException
     */
    public function follow(GatewayRequest $request, string $personaId): Response
    {
        $this->actor($request);
        return ApiResponse::success($this->socialClient->follow($personaId, $this->followerPersonaId($request), $this->mainMode($request)));
    }

    /**
     * @throws \Random\RandomException
     */
    public function unfollow(GatewayRequest $request, string $personaId): Response
    {
        $this->actor($request);
        return ApiResponse::success($this->socialClient->unfollow($personaId, $this->followerPersonaId($request), $this->mainMode($request)));
    }

    /**
     * @throws \Random\RandomException
     */
    public function block(GatewayRequest $request, string $userId): Response
    {
        $this->actor($request);
        return ApiResponse::success($this->socialClient->block($userId, $this->mainMode($request)));
    }

    /**
     * @throws \Random\RandomException
     */
    public function unblock(GatewayRequest $request, string $userId): Response
    {
        $this->actor($request);
        return ApiResponse::success($this->socialClient->unblock($userId, $this->mainMode($request)));
    }

    /**
     * @throws \Random\RandomException
     */
    private function like(GatewayRequest $request, int $targetType, string $targetId): Response
    {
        $this->actor($request);
        return ApiResponse::success($this->socialClient->like($this->personaId($request), $targetType, $targetId, $this->mainMode($request)));
    }

    /**
     * @throws \Random\RandomException
     */
    private function unlike(GatewayRequest $request, int $targetType, string $targetId): Response
    {
        $this->actor($request);
        return ApiResponse::success($this->socialClient->unlike($this->personaId($request), $targetType, $targetId, $this->mainMode($request)));
    }

    private function actor(GatewayRequest $request): void
    {
        $userId = trim($request->authContext['user_id']);
        if (blank($userId)) {
            throw new RuntimeException(ErrorCode::AUTH_UNAUTHORIZED->message(), ErrorCode::AUTH_UNAUTHORIZED->value);
        }
    }

    private function personaId(GatewayRequest $request): string
    {
        $body = trim((string)$request->post('persona_id', ''));
        if (filled($body)) {
            return $body;
        }

        return trim((string)($request->authContext['requested_persona_id'] ?? ''));
    }

    private function followerPersonaId(GatewayRequest $request): string
    {
        $body = trim((string)$request->post('follower_persona_id', ''));
        return filled($body) ? $body : $this->personaId($request);
    }

    private function mainMode(GatewayRequest $request): int
    {
        $raw = $request->post('identity_mode');
        if ($raw === null || $raw === '') {
            $raw = $request->header('x-identity-mode');
        }
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return IdentityMode::IDENTITY_MODE_MAIN;
        }
        $mode = is_int($raw) || (is_string($raw) && ctype_digit($raw))
            ? (int)$raw
            : match (strtolower(trim((string)$raw))) {
                'main' => IdentityMode::IDENTITY_MODE_MAIN,
                'alias' => IdentityMode::IDENTITY_MODE_ALIAS,
                default => 0,
            };
        if ($mode === IdentityMode::IDENTITY_MODE_ALIAS) {
            throw new RuntimeException(ErrorCode::PERSONA_FORBIDDEN->message(), ErrorCode::PERSONA_FORBIDDEN->value);
        }
        if ($mode !== IdentityMode::IDENTITY_MODE_MAIN) {
            throw new RuntimeException('identity_mode is invalid', ErrorCode::INVALID_ARGUMENT->value);
        }

        return $mode;
    }
}
