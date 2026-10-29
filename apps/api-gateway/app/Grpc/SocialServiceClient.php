<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Grpc;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\Social\V1\BatchGetInteractionStateRequest;
use Contracts\Gen\Social\V1\BatchGetInteractionStateResponse;
use Contracts\Gen\Social\V1\BlockRequest;
use Contracts\Gen\Social\V1\BlockResponse;
use Contracts\Gen\Social\V1\FollowRequest;
use Contracts\Gen\Social\V1\FollowResponse;
use Contracts\Gen\Social\V1\LikeRequest;
use Contracts\Gen\Social\V1\LikeResponse;
use Contracts\Gen\Social\V1\UnblockRequest;
use Contracts\Gen\Social\V1\UnblockResponse;
use Contracts\Gen\Social\V1\UnlikeRequest;
use Contracts\Gen\Social\V1\UnlikeResponse;
use Contracts\Gen\Social\V1\UnfollowRequest;
use Contracts\Gen\Social\V1\UnfollowResponse;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use RuntimeException;
use const Grpc\STATUS_DEADLINE_EXCEEDED;
use const Grpc\STATUS_INVALID_ARGUMENT;
use const Grpc\STATUS_NOT_FOUND;
use const Grpc\STATUS_PERMISSION_DENIED;
use const Grpc\STATUS_RESOURCE_EXHAUSTED;

final class SocialServiceClient
{
    private ?UnaryGrpcClient $client;

    public function __construct(
        private readonly ?string $target = null,
        private readonly int $timeoutMs = 500,
        private readonly ?string $serviceToken = null,
        ?UnaryGrpcClient $client = null,
    ) { $this->client = $client; }

    public function like(string $personaId, int $targetType, string $targetId, int $identityMode = 1): array
    {
        return ['success' => $this->call('/social.v1.SocialService/Like', new LikeRequest([
            'persona_id' => $personaId, 'target_type' => $targetType, 'target_id' => $targetId,
            'identity_mode' => $identityMode,
        ]), LikeResponse::class)->getSuccess()];
    }

    public function unlike(string $personaId, int $targetType, string $targetId, int $identityMode = 1): array
    {
        return ['success' => $this->call('/social.v1.SocialService/Unlike', new UnlikeRequest([
            'persona_id' => $personaId, 'target_type' => $targetType, 'target_id' => $targetId,
            'identity_mode' => $identityMode,
        ]), UnlikeResponse::class)->getSuccess()];
    }

    public function follow(string $followeePersonaId, string $followerPersonaId = '0', int $identityMode = 1): array
    {
        return ['success' => $this->call('/social.v1.SocialService/Follow', new FollowRequest([
            'follower_persona_id' => $followerPersonaId,
            'followee_persona_id' => $followeePersonaId,
            'identity_mode' => $identityMode,
        ]), FollowResponse::class)->getSuccess()];
    }

    public function unfollow(string $followeePersonaId, string $followerPersonaId = '0', int $identityMode = 1): array
    {
        return ['success' => $this->call('/social.v1.SocialService/Unfollow', new UnfollowRequest([
            'follower_persona_id' => $followerPersonaId,
            'followee_persona_id' => $followeePersonaId,
            'identity_mode' => $identityMode,
        ]), UnfollowResponse::class)->getSuccess()];
    }

    public function block(string $blockedUserId, int $identityMode = 1): array
    {
        return ['success' => $this->call('/social.v1.SocialService/Block', new BlockRequest([
            'blocked_user_id' => $blockedUserId, 'identity_mode' => $identityMode,
        ]), BlockResponse::class)->getSuccess()];
    }

    public function unblock(string $blockedUserId, int $identityMode = 1): array
    {
        return ['success' => $this->call('/social.v1.SocialService/Unblock', new UnblockRequest([
            'blocked_user_id' => $blockedUserId, 'identity_mode' => $identityMode,
        ]), UnblockResponse::class)->getSuccess()];
    }

    /** @param list<string> $targetIds */
    public function interactionState(string $personaId, int $targetType, array $targetIds): array
    {
        $response = $this->call('/social.v1.SocialService/BatchGetInteractionState', new BatchGetInteractionStateRequest([
            'persona_id' => $personaId, 'target_type' => $targetType, 'target_ids' => $targetIds,
            'identity_mode' => 1,
        ]), BatchGetInteractionStateResponse::class);
        $states = [];
        foreach ($response->getStates() as $state) {
            $states[] = [
                'target_id' => (string)$state->getTargetId(),
                'is_liked' => $state->getIsLiked(),
                'is_followed' => $state->getIsFollowed(),
            ];
        }
        return ['states' => $states];
    }

    /**
     * @template T of Message
     * @param class-string<T> $responseClass
     * @return T
     * @throws \Exception
     */
    private function call(string $method, Message $request, string $responseClass): Message
    {
        $client = $this->client ??= new UnaryGrpcClient(
            $this->target ?: config('gateway.grpc.social.target', 'social-service:50054'), $this->serviceToken,
        );
        try {
            return $client->call($method, $request, $responseClass, $this->timeoutMs);
        } catch (GrpcCallException $e) {
            $code = $e->appErrorCode ?? match ($e->grpcStatus) {
                STATUS_DEADLINE_EXCEEDED => ErrorCode::RPC_TIMEOUT->value,
                STATUS_NOT_FOUND => ErrorCode::USER_NOT_FOUND->value,
                STATUS_PERMISSION_DENIED => ErrorCode::PERSONA_FORBIDDEN->value,
                STATUS_INVALID_ARGUMENT => ErrorCode::INVALID_ARGUMENT->value,
                STATUS_RESOURCE_EXHAUSTED => ErrorCode::RATE_LIMIT_EXCEEDED->value,
                default => ErrorCode::SERVICE_UNAVAILABLE->value,
            };
            $message = in_array($code, [ErrorCode::RPC_TIMEOUT->value, ErrorCode::SERVICE_UNAVAILABLE->value], true)
                ? ErrorCode::from($code)->message() : $e->getMessage();
            throw new RuntimeException($message, $code, $e);
        }
    }
}
