<?php

declare(strict_types=1);

/**
 * CR05 cross-process Social/Content/Chat/Message authorization probe.
 *
 * Every rejection is asserted against its gRPC status and, where the server
 * emits it, its business error trailer. Any unexpected success, INTERNAL,
 * UNAVAILABLE, or DEADLINE failure terminates with exit code 1.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Contracts\Errors\ErrorCode;
use Contracts\Gen\Chat\V1\GetOrCreatePrivateConversationRequest;
use Contracts\Gen\Chat\V1\GetOrCreatePrivateConversationResponse;
use Contracts\Gen\Chat\V1\PublicChatTarget;
use Contracts\Gen\Common\V1\IdentityMode;
use Contracts\Gen\Content\V1\CreateCommentRequest;
use Contracts\Gen\Content\V1\CreateCommentResponse;
use Contracts\Gen\Content\V1\CreatePostRequest;
use Contracts\Gen\Content\V1\CreatePostResponse;
use Contracts\Gen\Content\V1\GetPostRequest;
use Contracts\Gen\Content\V1\GetPostResponse;
use Contracts\Gen\Message\V1\PublicSendMessageRequest;
use Contracts\Gen\Message\V1\SendMessageResponse;
use Contracts\Gen\Social\V1\BlockRequest;
use Contracts\Gen\Social\V1\BlockResponse;
use Contracts\Gen\Social\V1\CheckBidirectionalBlockRequest;
use Contracts\Gen\Social\V1\CheckBidirectionalBlockResponse;
use Contracts\Gen\Social\V1\FollowRequest;
use Contracts\Gen\Social\V1\FollowResponse;
use Contracts\Gen\Social\V1\LikeRequest;
use Contracts\Gen\Social\V1\LikeResponse;
use Contracts\Gen\Social\V1\UnblockRequest;
use Contracts\Gen\Social\V1\UnblockResponse;
use Contracts\Gen\Social\V1\UnfollowRequest;
use Contracts\Gen\Social\V1\UnfollowResponse;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use Packages\Tracing\TraceContext;
use Spiral\RoadRunner\GRPC\StatusCode;

final class SocialRpcProbe
{
    /** @var array<string, string> */
    private array $targets;

    /** @var array<string, string> */
    private array $gatewayTokens;

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    private string $userA;
    private string $userB;
    private string $personaA;
    private string $personaB;

    public function __construct()
    {
        $this->targets = [
            'content' => $this->env('RPC_CONTENT_TARGET', '127.0.0.1:50053'),
            'social' => $this->env('RPC_SOCIAL_TARGET', '127.0.0.1:50054'),
            'chat' => $this->env('RPC_CHAT_TARGET', '127.0.0.1:50055'),
            'message' => $this->env('RPC_MESSAGE_TARGET', '127.0.0.1:50056'),
        ];
        $this->gatewayTokens = [
            'content' => $this->requiredEnv('RPC_GATEWAY_CONTENT_TOKEN'),
            'social' => $this->requiredEnv('RPC_GATEWAY_SOCIAL_TOKEN'),
            'chat' => $this->requiredEnv('RPC_GATEWAY_CHAT_TOKEN'),
            'message' => $this->requiredEnv('RPC_GATEWAY_MESSAGE_TOKEN'),
        ];
        $this->userA = $this->env('RPC_USER_A', '1001');
        $this->userB = $this->env('RPC_USER_B', '1002');
        $this->personaA = $this->env('RPC_MAIN_PERSONA_A', '2001');
        $this->personaB = $this->env('RPC_MAIN_PERSONA_B', '2002');
    }

    public function run(): void
    {
        $runId = 'cr05-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        $post = $this->success('content.create_post_as_user_b', function () use ($runId): array {
            /** @var CreatePostResponse $response */
            $response = $this->actorCall(
                'content', $this->gatewayTokens['content'], '/content.v1.ContentService/CreatePost',
                new CreatePostRequest([
                    'request_id' => $runId . '-post-b', 'persona_id' => $this->personaB, 'type' => 1,
                    'content' => '{"cr05":"post-b"}', 'visibility' => 1,
                    'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
                ]), CreatePostResponse::class, $this->userB, $this->personaB, $runId . '-post-b',
            );
            $id = (string)$response->getPost()->getPostId();
            $this->assertPositiveId($id, 'Content post id');

            return ['post_id' => $id];
        });
        $postId = (string)$post['post_id'];

        $comment = $this->success('content.create_comment_as_user_a', function () use ($postId, $runId): array {
            /** @var CreateCommentResponse $response */
            $response = $this->actorCall(
                'content', $this->gatewayTokens['content'], '/content.v1.ContentService/CreateComment',
                new CreateCommentRequest([
                    'request_id' => $runId . '-comment-a', 'post_id' => $postId, 'persona_id' => $this->personaA,
                    'content' => '{"cr05":"comment-a"}', 'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
                ]), CreateCommentResponse::class, $this->userA, $this->personaA, $runId . '-comment-a',
            );
            $id = (string)$response->getComment()->getCommentId();
            $this->assertPositiveId($id, 'Content comment id');

            return ['comment_id' => $id];
        });

        $this->success('social.like_main_post', function () use ($postId, $runId): array {
            /** @var LikeResponse $response */
            $response = $this->actorCall(
                'social', $this->gatewayTokens['social'], '/social.v1.SocialService/Like',
                new LikeRequest([
                    'persona_id' => $this->personaA, 'target_type' => 1, 'target_id' => $postId,
                    'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
                ]), LikeResponse::class, $this->userA, $this->personaA, $runId . '-like',
            );
            $this->assert($response->getSuccess(), 'MAIN like must succeed');

            return ['success' => true];
        });

        $this->expectFailure(
            'social.explicit_alias_rejected',
            function () use ($postId, $runId): void {
                $this->actorCall(
                    'social', $this->gatewayTokens['social'], '/social.v1.SocialService/Like',
                    new LikeRequest([
                        'persona_id' => $this->personaA, 'target_type' => 1, 'target_id' => $postId,
                        'identity_mode' => IdentityMode::IDENTITY_MODE_ALIAS,
                    ]), LikeResponse::class, $this->userA, $this->personaA, $runId . '-alias',
                );
            },
            [ErrorCode::PERSONA_FORBIDDEN->value], [StatusCode::PERMISSION_DENIED], true,
        );
        $this->expectFailure(
            'social.foreign_persona_rejected',
            function () use ($postId, $runId): void {
                $this->actorCall(
                    'social', $this->gatewayTokens['social'], '/social.v1.SocialService/Like',
                    new LikeRequest([
                        'persona_id' => $this->personaB, 'target_type' => 1, 'target_id' => $postId,
                        'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
                    ]), LikeResponse::class, $this->userA, $this->personaA, $runId . '-foreign',
                );
            },
            [ErrorCode::PERSONA_FORBIDDEN->value], [StatusCode::PERMISSION_DENIED],
        );

        $this->success('social.follow_main_to_active_main', function () use ($runId): array {
            /** @var FollowResponse $response */
            $response = $this->actorCall(
                'social', $this->gatewayTokens['social'], '/social.v1.SocialService/Follow',
                new FollowRequest([
                    'follower_persona_id' => $this->personaA, 'followee_persona_id' => $this->personaB,
                    'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
                ]), FollowResponse::class, $this->userA, $this->personaA, $runId . '-follow',
            );
            $this->assert($response->getSuccess(), 'MAIN follow must succeed');

            return ['success' => true];
        });
        $this->success('social.unfollow_main_to_main', function () use ($runId): array {
            /** @var UnfollowResponse $response */
            $response = $this->actorCall(
                'social', $this->gatewayTokens['social'], '/social.v1.SocialService/Unfollow',
                new UnfollowRequest([
                    'follower_persona_id' => $this->personaA, 'followee_persona_id' => $this->personaB,
                    'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
                ]), UnfollowResponse::class, $this->userA, $this->personaA, $runId . '-unfollow',
            );
            $this->assert($response->getSuccess(), 'MAIN unfollow must succeed');

            return ['success' => true];
        });

        $conversation = $this->success('chat.create_main_to_main', function () use ($runId): array {
            /** @var GetOrCreatePrivateConversationResponse $response */
            $response = $this->actorCall(
                'chat', $this->gatewayTokens['chat'], '/chat.v1.ChatService/GetOrCreatePrivateConversation',
                new GetOrCreatePrivateConversationRequest([
                    'request_id' => $runId . '-conversation', 'user_id' => $this->userA,
                    'persona_id' => $this->personaA, 'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
                    'target' => new PublicChatTarget(['main_persona_id' => $this->personaB]),
                ]), GetOrCreatePrivateConversationResponse::class, $this->userA, $this->personaA, $runId . '-conversation',
            );
            $id = (string)$response->getConversation()->getConversationId();
            $this->assertPositiveId($id, 'Conversation id');

            return ['conversation_id' => $id];
        });
        $conversationId = (string)$conversation['conversation_id'];

        $this->success('message.send_main_message', function () use ($conversationId, $runId): array {
            /** @var SendMessageResponse $response */
            $response = $this->actorCall(
                'message', $this->gatewayTokens['message'], '/message.v1.MessageService/SendMessage',
                new PublicSendMessageRequest([
                    'client_message_id' => $runId . '-m', 'conversation_id' => $conversationId,
                    'type' => 1, 'content' => '{"cr05":"before-block"}',
                ]), SendMessageResponse::class, $this->userA, $this->personaA, $runId . '-message',
            );
            $id = (string)$response->getMessage()->getMessageId();
            $this->assertPositiveId($id, 'Message id');

            return ['message_id' => $id];
        });

        $this->success('social.block_a_to_b', function () use ($runId): array {
            /** @var BlockResponse $response */
            $response = $this->actorCall(
                'social', $this->gatewayTokens['social'], '/social.v1.SocialService/Block',
                new BlockRequest([
                    'blocker_user_id' => $this->userA, 'blocked_user_id' => $this->userB,
                    'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
                ]), BlockResponse::class, $this->userA, $this->personaA, $runId . '-block-a-b',
            );
            $this->assert($response->getSuccess(), 'A to B block must succeed');

            return ['success' => true];
        });
        $this->assertInternalBlock('social.internal_block_a_b_true', true, $runId . '-check-1');

        $this->expectFailure(
            'content.blocked_post_rejected',
            function () use ($postId, $runId): void {
                $this->actorCall(
                    'content', $this->gatewayTokens['content'], '/content.v1.ContentService/GetPost',
                    new GetPostRequest(['post_id' => $postId]), GetPostResponse::class,
                    $this->userA, $this->personaA, $runId . '-blocked-read',
                );
            },
            [ErrorCode::PERSONA_FORBIDDEN->value], [StatusCode::PERMISSION_DENIED],
        );
        $this->expectFailure(
            'social.blocked_like_rejected',
            function () use ($postId, $runId): void {
                $this->actorCall(
                    'social', $this->gatewayTokens['social'], '/social.v1.SocialService/Like',
                    new LikeRequest([
                        'persona_id' => $this->personaA, 'target_type' => 1, 'target_id' => $postId,
                        'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
                    ]), LikeResponse::class, $this->userA, $this->personaA, $runId . '-blocked-like',
                );
            },
            [ErrorCode::SOCIAL_USER_BLOCKED->value], [StatusCode::PERMISSION_DENIED],
        );
        $this->expectFailure(
            'chat.blocked_target_rejected',
            function () use ($runId): void {
                $this->actorCall(
                    'chat', $this->gatewayTokens['chat'], '/chat.v1.ChatService/GetOrCreatePrivateConversation',
                    new GetOrCreatePrivateConversationRequest([
                        'request_id' => $runId . '-blocked-chat', 'user_id' => $this->userA,
                        'persona_id' => $this->personaA, 'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
                        'target' => new PublicChatTarget(['main_persona_id' => $this->personaB]),
                    ]), GetOrCreatePrivateConversationResponse::class,
                    $this->userA, $this->personaA, $runId . '-blocked-chat',
                );
            },
            [ErrorCode::CHAT_FORBIDDEN_BY_PRIVACY->value], [StatusCode::PERMISSION_DENIED],
        );
        $this->expectFailure(
            'message.blocked_send_rejected',
            function () use ($conversationId, $runId): void {
                $this->actorCall(
                    'message', $this->gatewayTokens['message'], '/message.v1.MessageService/SendMessage',
                    new PublicSendMessageRequest([
                        'client_message_id' => $runId . '-b', 'conversation_id' => $conversationId,
                        'type' => 1, 'content' => '{"cr05":"blocked"}',
                    ]), SendMessageResponse::class, $this->userA, $this->personaA, $runId . '-blocked-message',
                );
            },
            [ErrorCode::CHAT_FORBIDDEN_BY_PRIVACY->value], [StatusCode::PERMISSION_DENIED],
        );

        $this->success('social.unblock_a_to_b', function () use ($runId): array {
            /** @var UnblockResponse $response */
            $response = $this->actorCall(
                'social', $this->gatewayTokens['social'], '/social.v1.SocialService/Unblock',
                new UnblockRequest([
                    'blocker_user_id' => $this->userA, 'blocked_user_id' => $this->userB,
                    'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
                ]), UnblockResponse::class, $this->userA, $this->personaA, $runId . '-unblock-a-b',
            );
            $this->assert($response->getSuccess(), 'A to B unblock must succeed');

            return ['success' => true];
        });
        $this->success('social.block_b_to_a', function () use ($runId): array {
            /** @var BlockResponse $response */
            $response = $this->actorCall(
                'social', $this->gatewayTokens['social'], '/social.v1.SocialService/Block',
                new BlockRequest([
                    'blocker_user_id' => $this->userB, 'blocked_user_id' => $this->userA,
                    'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
                ]), BlockResponse::class, $this->userB, $this->personaB, $runId . '-block-b-a',
            );
            $this->assert($response->getSuccess(), 'B to A block must succeed');

            return ['success' => true];
        });
        $this->assertInternalBlock('social.internal_block_reverse_true', true, $runId . '-check-2');
        $this->success('social.unblock_a_to_b_without_edge', function () use ($runId): array {
            /** @var UnblockResponse $response */
            $response = $this->actorCall(
                'social', $this->gatewayTokens['social'], '/social.v1.SocialService/Unblock',
                new UnblockRequest([
                    'blocker_user_id' => $this->userA, 'blocked_user_id' => $this->userB,
                    'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
                ]), UnblockResponse::class, $this->userA, $this->personaA, $runId . '-unblock-empty',
            );
            $this->assert($response->getSuccess(), 'Unblock without an edge must be idempotent');

            return ['success' => true];
        });
        $this->assertInternalBlock('social.internal_block_after_one_edge_removed_true', true, $runId . '-check-3');
        $this->success('social.unblock_b_to_a', function () use ($runId): array {
            /** @var UnblockResponse $response */
            $response = $this->actorCall(
                'social', $this->gatewayTokens['social'], '/social.v1.SocialService/Unblock',
                new UnblockRequest([
                    'blocker_user_id' => $this->userB, 'blocked_user_id' => $this->userA,
                    'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
                ]), UnblockResponse::class, $this->userB, $this->personaB, $runId . '-unblock-b-a',
            );
            $this->assert($response->getSuccess(), 'B to A unblock must succeed');

            return ['success' => true];
        });
        $this->assertInternalBlock('social.internal_block_after_both_edges_removed_false', false, $runId . '-check-4');

        echo json_encode([
            'ok' => true,
            'run_id' => $runId,
            'post_id' => $postId,
            'comment_id' => (string)$comment['comment_id'],
            'conversation_id' => $conversationId,
            'steps' => $this->rows,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    /** @return array<string, mixed> */
    private function success(string $label, callable $operation): array
    {
        try {
            $details = $operation();
        } catch (Throwable $e) {
            throw new RuntimeException('Unexpected failure at ' . $label . ': ' . $this->describe($e), 0, $e);
        }
        if (!is_array($details)) {
            $details = ['value' => $details];
        }
        $this->rows[] = ['step' => $label, 'ok' => true] + $details;

        return $details;
    }

    /**
     * @param list<int> $expectedAppCodes
     * @param list<int> $expectedGrpcStatuses
     */
    private function expectFailure(
        string $label,
        callable $operation,
        array $expectedAppCodes,
        array $expectedGrpcStatuses,
        bool $allowMissingAppCode = false,
    ): void {
        $caught = null;
        try {
            $operation();
        } catch (Throwable $e) {
            $caught = $e;
        }
        if (!$caught instanceof GrpcCallException) {
            if ($caught === null) {
                throw new RuntimeException('Expected RPC rejection did not occur: ' . $label);
            }
            throw new RuntimeException('Unexpected non-gRPC failure at ' . $label . ': ' . $this->describe($caught), 0, $caught);
        }
        if (!in_array($caught->grpcStatus, $expectedGrpcStatuses, true)) {
            throw new RuntimeException(sprintf(
                'Unexpected gRPC status at %s: got %d, expected [%s], details=%s',
                $label, $caught->grpcStatus, implode(',', $expectedGrpcStatuses), $caught->getMessage(),
            ), 0, $caught);
        }
        if ($caught->appErrorCode === null) {
            if (!$allowMissingAppCode) {
                throw new RuntimeException('Missing business error trailer at ' . $label, 0, $caught);
            }
        } elseif (!in_array($caught->appErrorCode, $expectedAppCodes, true)) {
            throw new RuntimeException(sprintf(
                'Unexpected business error at %s: got %d, expected [%s], details=%s',
                $label, $caught->appErrorCode, implode(',', $expectedAppCodes), $caught->getMessage(),
            ), 0, $caught);
        }
        $this->rows[] = [
            'step' => $label,
            'ok' => true,
            'expected_rejection' => true,
            'grpc_status' => $caught->grpcStatus,
            'app_error_code' => $caught->appErrorCode,
        ];
    }

    private function assertInternalBlock(string $label, bool $expected, string $requestId): void
    {
        $this->success($label, function () use ($expected, $requestId): array {
            $previous = getenv('SERVICE_NAME');
            putenv('SERVICE_NAME=content-service');
            TraceContext::reset();
            try {
                $client = new UnaryGrpcClient($this->targets['social'], $this->requiredEnv('RPC_CONTENT_SOCIAL_TOKEN'));
                /** @var CheckBidirectionalBlockResponse $response */
                $response = $client->call(
                    '/social.v1.SocialService/CheckBidirectionalBlock',
                    new CheckBidirectionalBlockRequest(['user_id_a' => $this->userA, 'user_id_b' => $this->userB]),
                    CheckBidirectionalBlockResponse::class,
                    5000,
                    ['x-request-id' => [$requestId], 'x-trace-id' => [$requestId]],
                );
            } finally {
                TraceContext::reset();
                if ($previous === false) {
                    putenv('SERVICE_NAME');
                } else {
                    putenv('SERVICE_NAME=' . $previous);
                }
            }
            $actual = $response->getBlocked();
            $this->assert($actual === $expected, 'Expected blocked=' . ($expected ? 'true' : 'false') . ', got ' . ($actual ? 'true' : 'false'));

            return ['blocked' => $actual];
        });
    }

    /** @return Message */
    private function actorCall(
        string $service,
        string $token,
        string $method,
        Message $request,
        string $responseClass,
        string $userId,
        string $personaId,
        string $requestId,
    ): Message {
        $previous = getenv('SERVICE_NAME');
        putenv('SERVICE_NAME=api-gateway');
        TraceContext::setCurrent(TraceContext::create(requestId: $requestId, userId: $userId, personaId: $personaId));
        try {
            return (new UnaryGrpcClient($this->targets[$service], $token))->call(
                $method, $request, $responseClass, 5000,
            );
        } finally {
            TraceContext::reset();
            if ($previous === false) {
                putenv('SERVICE_NAME');
            } else {
                putenv('SERVICE_NAME=' . $previous);
            }
        }
    }

    private function assertPositiveId(string $value, string $name): void
    {
        $this->assert(preg_match('/\A[1-9][0-9]*\z/', $value) === 1, $name . ' must be positive');
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    private function describe(Throwable $e): string
    {
        if ($e instanceof GrpcCallException) {
            return sprintf('grpc=%d app=%s %s', $e->grpcStatus, $e->appErrorCode === null ? 'none' : (string)$e->appErrorCode, $e->getMessage());
        }

        return $e::class . ': ' . $e->getMessage();
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || trim($value) === '' ? $default : trim($value);
    }

    private function requiredEnv(string $name): string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            throw new RuntimeException('Missing required test environment variable: ' . $name);
        }

        return trim($value);
    }
}

try {
    (new SocialRpcProbe())->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'social_rpc_probe failed: ' . $e::class . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
