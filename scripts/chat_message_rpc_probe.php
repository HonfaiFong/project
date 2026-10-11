<?php

declare(strict_types=1);

/**
 * Cross-process CR02/03 probe for the cr05 RPC fixture.
 *
 * The probe intentionally has no production credentials or fixed service
 * tokens. Set RPC_* variables before running it in a test container. The
 * caller identity is always carried in x-user-id metadata. Public Chat and
 * Message body ids are populated with spoof values in a few calls to verify
 * they are ignored; Social's explicit blocker id remains a consistency check.
 * Required variables are RPC_GATEWAY_USER_TOKEN, RPC_GATEWAY_CONTENT_TOKEN,
 * RPC_GATEWAY_SOCIAL_TOKEN, RPC_GATEWAY_CHAT_TOKEN, and
 * RPC_GATEWAY_MESSAGE_TOKEN. RPC_*_TARGET variables default to the shared
 * cr05 test namespace ports 50052 through 50056.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Contracts\Errors\ErrorCode;
use Contracts\Gen\Chat\V1\AnonymousChatTarget;
use Contracts\Gen\Chat\V1\GetOrCreatePrivateConversationRequest;
use Contracts\Gen\Chat\V1\GetOrCreatePrivateConversationResponse;
use Contracts\Gen\Chat\V1\PublicChatTarget;
use Contracts\Gen\Chat\V1\PublicConversation;
use Contracts\Gen\Chat\V1\PublicConversationMember;
use Contracts\Gen\Common\V1\IdentityMode;
use Contracts\Gen\Common\V1\PaginationRequest;
use Contracts\Gen\Content\V1\CreatePostRequest;
use Contracts\Gen\Content\V1\CreatePostResponse;
use Contracts\Gen\Content\V1\DeletePostRequest;
use Contracts\Gen\Content\V1\DeletePostResponse;
use Contracts\Gen\Message\V1\GetMessagesRequest;
use Contracts\Gen\Message\V1\GetMessagesResponse;
use Contracts\Gen\Message\V1\PublicMessage;
use Contracts\Gen\Message\V1\PublicSendMessageRequest;
use Contracts\Gen\Message\V1\SendMessageResponse;
use Contracts\Gen\Message\V1\SyncMessagesRequest;
use Contracts\Gen\Message\V1\SyncMessagesResponse;
use Contracts\Gen\Social\V1\BlockRequest;
use Contracts\Gen\Social\V1\BlockResponse;
use Contracts\Gen\Social\V1\UnblockRequest;
use Contracts\Gen\Social\V1\UnblockResponse;
use Contracts\Gen\User\V1\GetPersonaRequest;
use Contracts\Gen\User\V1\GetPersonaResponse;
use Contracts\Gen\User\V1\ResolvePersonaModeRequest;
use Contracts\Gen\User\V1\ResolvePersonaModeResponse;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use Spiral\RoadRunner\GRPC\StatusCode;

final class ChatMessageRpcProbe
{
    /** @var array<string, string> */
    private array $targets;

    /** @var array<string, string> */
    private array $gatewayTokens;

    public function __construct()
    {
        $this->targets = [
            'user' => $this->env('RPC_USER_TARGET', '127.0.0.1:50052'),
            'content' => $this->env('RPC_CONTENT_TARGET', '127.0.0.1:50053'),
            'social' => $this->env('RPC_SOCIAL_TARGET', '127.0.0.1:50054'),
            'chat' => $this->env('RPC_CHAT_TARGET', '127.0.0.1:50055'),
            'message' => $this->env('RPC_MESSAGE_TARGET', '127.0.0.1:50056'),
        ];
        $this->gatewayTokens = [
            'user' => $this->requiredEnv('RPC_GATEWAY_USER_TOKEN'),
            'content' => $this->requiredEnv('RPC_GATEWAY_CONTENT_TOKEN'),
            'social' => $this->requiredEnv('RPC_GATEWAY_SOCIAL_TOKEN'),
            'chat' => $this->requiredEnv('RPC_GATEWAY_CHAT_TOKEN'),
            'message' => $this->requiredEnv('RPC_GATEWAY_MESSAGE_TOKEN'),
        ];
    }

    public function run(): void
    {
        $userA = $this->env('RPC_USER_A', '1001');
        $userB = $this->env('RPC_USER_B', '1002');
        $mainA = $this->env('RPC_MAIN_PERSONA_A', '2001');
        $mainB = $this->env('RPC_MAIN_PERSONA_B', '2002');
        // Keep request/client ids comfortably below the 36-byte client id
        // contract while retaining enough entropy for concurrent probes.
        $runId = 'cm-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));

        // Verify the real UserService path and pre-create the canonical
        // aliases. The first alias creation may exceed the normal 250ms
        // business hop while the fixture warms its MySQL connection; Content
        // and Chat then use the already persisted alias through their normal
        // 250ms RPC budget.
        $this->call(
            'user',
            'api-gateway',
            $this->gatewayTokens['user'],
            '/user.v1.UserService/GetPersona',
            new GetPersonaRequest(['persona_id' => $mainA]),
            GetPersonaResponse::class,
            $userA,
            $runId . '-user-a',
        );
        $this->call(
            'user',
            'api-gateway',
            $this->gatewayTokens['user'],
            '/user.v1.UserService/GetPersona',
            new GetPersonaRequest(['persona_id' => $mainB]),
            GetPersonaResponse::class,
            $userB,
            $runId . '-user-b',
        );
        $this->call(
            'user',
            'api-gateway',
            $this->gatewayTokens['user'],
            '/user.v1.UserService/ResolvePersonaMode',
            new ResolvePersonaModeRequest(['user_id' => $userA, 'mode' => 2, 'operation' => 3]),
            ResolvePersonaModeResponse::class,
            $userA,
            $runId . '-alias-a',
            5000,
        );
        $this->call(
            'user',
            'api-gateway',
            $this->gatewayTokens['user'],
            '/user.v1.UserService/ResolvePersonaMode',
            new ResolvePersonaModeRequest(['user_id' => $userB, 'mode' => 2, 'operation' => 3]),
            ResolvePersonaModeResponse::class,
            $userB,
            $runId . '-alias-b',
            5000,
        );

        $this->expectFailure(
            'spoofed content persona',
            function () use ($userB, $runId): void {
                $this->call(
                    'content',
                    'api-gateway',
                    $this->gatewayTokens['content'],
                    '/content.v1.ContentService/CreatePost',
                    new CreatePostRequest([
                        'request_id' => $runId . '-spoofed-post',
                        'persona_id' => '999999',
                        'type' => 1,
                        'content' => 'CR02/03 RPC probe spoofed persona',
                        'visibility' => 1,
                        'identity_mode' => IdentityMode::IDENTITY_MODE_ALIAS,
                    ]),
                    CreatePostResponse::class,
                    $userB,
                    $runId . '-spoofed-post',
                    5000,
                );
            },
            [ErrorCode::PERSONA_FORBIDDEN->value],
            [StatusCode::PERMISSION_DENIED],
        );

        $postResponse = $this->call(
            'content',
            'api-gateway',
            $this->gatewayTokens['content'],
            '/content.v1.ContentService/CreatePost',
            new CreatePostRequest([
                'request_id' => $runId . '-post',
                // An omitted persona is valid input; Content resolves the
                // active identity from trusted metadata and rejects a
                // non-matching explicit persona above.
                'persona_id' => '0',
                'type' => 1,
                'content' => 'CR02/03 RPC probe anonymous post',
                'visibility' => 1,
                'identity_mode' => IdentityMode::IDENTITY_MODE_ALIAS,
                'alias_display_name' => 'RPC probe alias',
            ]),
            CreatePostResponse::class,
            $userB,
            $runId . '-post',
            5000,
        );
        $post = $postResponse->getPost();
        if ($post === null || $post->getAuthor() === null) {
            throw new RuntimeException('ContentService did not return a post author snapshot');
        }
        $postId = (string)$post->getPostId();
        $contentRef = trim($post->getAuthor()->getAnonymousRef());
        $this->assert($postId !== '0' && $contentRef !== '', 'Content alias post must have an opaque reference');
        $this->assert(
                (int)$post->getAuthor()->getIdentityMode() === IdentityMode::IDENTITY_MODE_ALIAS
            && !$post->getAuthor()->getProfileAccessible(),
            'Content public projection must remain anonymous',
        );

        $target = new PublicChatTarget([
            'anonymous' => new AnonymousChatTarget([
                'scope_type' => 'post',
                'scope_id' => $postId,
                'anonymous_ref' => $contentRef,
            ]),
        ]);
        $createRequest = new GetOrCreatePrivateConversationRequest([
            // These values deliberately do not match x-user-id/persona. Chat
            // must use the authenticated metadata and never this body pair.
            'request_id' => $runId . '-chat-main',
            'user_id' => '999999',
            'persona_id' => '999999',
            'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
            'alias_display_name' => 'spoofed body identity',
            'target' => $target,
        ]);
        $mainConversation = $this->chatCreate($createRequest, $userA, $runId . '-chat-main');
        $mainId = (string)$mainConversation->getConversationId();
        $mainAuthor = $this->author($mainConversation, IdentityMode::IDENTITY_MODE_MAIN);
        $aliasAuthor = $this->author($mainConversation, IdentityMode::IDENTITY_MODE_ALIAS);
        $this->assert((string)$mainAuthor->getPersonaId() === $mainA, 'Chat must derive MAIN from authenticated account');
        $this->assert(
            (string)$aliasAuthor->getPersonaId() === '0',
            'Anonymous peer must not expose persona id; got ' . (string)$aliasAuthor->getPersonaId(),
        );
        $this->assert(
            $aliasAuthor->getAnonymousRef() !== '' && $aliasAuthor->getAnonymousRef() !== $contentRef,
            'Chat must convert the content reference to a conversation-scoped reference',
        );
        $this->assert($aliasAuthor->getDisplayName() === $post->getAuthor()->getDisplayName(), 'Chat must freeze Content display snapshot');

        $sameConversation = $this->chatCreate($createRequest, $userA, $runId . '-chat-repeat');
        $this->assert((string)$sameConversation->getConversationId() === $mainId, 'Repeated MAIN get-or-create must be idempotent');
        $this->assert(
            $this->author($sameConversation, IdentityMode::IDENTITY_MODE_ALIAS)->getAnonymousRef() === $aliasAuthor->getAnonymousRef(),
            'Repeated get-or-create must keep the immutable alias snapshot',
        );

        $aliasSourceConversation = $this->chatCreate(
            new GetOrCreatePrivateConversationRequest([
                'request_id' => $runId . '-chat-alias',
                'user_id' => $userA,
                'persona_id' => $mainA,
                'identity_mode' => IdentityMode::IDENTITY_MODE_ALIAS,
                'alias_display_name' => 'RPC source alias',
                'target' => $target,
            ]),
            $userA,
            $runId . '-chat-alias',
        );
        $this->assert(
            (string)$aliasSourceConversation->getConversationId() !== $mainId,
            'Switching the source MAIN/ALIAS identity must not reveal the old session',
        );
        $this->assert(
            $this->author($aliasSourceConversation, IdentityMode::IDENTITY_MODE_ALIAS)->getDisplayName() === 'RPC source alias',
            'Creator alias display must be frozen in the conversation snapshot',
        );

        $this->expectFailure('cross-scope target', function () use ($target, $userA, $runId): void {
            $wrongScope = new PublicChatTarget([
                'anonymous' => new AnonymousChatTarget([
                    'scope_type' => 'post',
                    'scope_id' => '999999999999',
                    'anonymous_ref' => $target->getAnonymous()->getAnonymousRef(),
                ]),
            ]);
            $this->chatCreate(
                new GetOrCreatePrivateConversationRequest([
                    'request_id' => $runId . '-wrong-scope',
                    'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
                    'target' => $wrongScope,
                ]),
                $userA,
                $runId . '-wrong-scope',
            );
        }, [ErrorCode::CONTENT_COMMENT_NOT_FOUND->value], [StatusCode::NOT_FOUND]);
        $this->expectFailure('unknown anonymous reference', function () use ($target, $userA, $runId): void {
            $unknown = new PublicChatTarget([
                'anonymous' => new AnonymousChatTarget([
                    'scope_type' => 'post',
                    'scope_id' => $target->getAnonymous()->getScopeId(),
                    'anonymous_ref' => 'anon_unknown_reference',
                ]),
            ]);
            $this->chatCreate(
                new GetOrCreatePrivateConversationRequest([
                    'request_id' => $runId . '-unknown-ref',
                    'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
                    'target' => $unknown,
                ]),
                $userA,
                $runId . '-unknown-ref',
            );
        }, [ErrorCode::CONTENT_COMMENT_NOT_FOUND->value], [StatusCode::NOT_FOUND]);

        $messageClientId = 'm-' . bin2hex(random_bytes(12));
        $message = $this->sendMessage($userA, $mainId, $messageClientId, '{"probe":"first"}');
        $this->assert((string)$message->getAuthor()->getPersonaId() === $mainA, 'Message must use Chat MAIN snapshot');
        $sameMessage = $this->sendMessage($userA, $mainId, $messageClientId, '{"probe":"first"}');
        $this->assert((string)$sameMessage->getMessageId() === (string)$message->getMessageId(), 'Message send must be idempotent');
        $this->assert((string)$sameMessage->getSeq() === (string)$message->getSeq(), 'Idempotent message must keep seq');
        $this->expectFailure('idempotency payload switch', function () use ($userA, $mainId, $messageClientId): void {
            $this->sendMessage($userA, $mainId, $messageClientId, '{"probe":"switched"}');
        }, [ErrorCode::MESSAGE_DUPLICATE_CLIENT_ID->value], [StatusCode::ALREADY_EXISTS]);

        $sync = $this->syncMessages($userA, $mainId, '999999', $runId . '-sync');
        $this->assert(count($sync->getMessages()) >= 1, 'Message sync must read persisted rows through Chat authorization');
        $listed = $this->getMessages($userA, $mainId, $runId . '-list');
        $this->assert(count($listed->getMessages()) >= 1, 'Message list must read persisted rows through Chat authorization');

        $this->block($userA, $userB, $runId . '-block');
        $this->expectFailure('blocked message send', function () use ($userA, $mainId, $runId): void {
            $this->sendMessage($userA, $mainId, $runId . '-blocked', '{"probe":"blocked"}');
        }, [ErrorCode::CHAT_FORBIDDEN_BY_PRIVACY->value], [StatusCode::PERMISSION_DENIED]);
        $this->expectFailure('blocked message read', function () use ($userA, $mainId, $runId): void {
            $this->syncMessages($userA, $mainId, '0', $runId . '-blocked-read');
        }, [ErrorCode::CHAT_FORBIDDEN_BY_PRIVACY->value], [StatusCode::PERMISSION_DENIED]);
        $this->expectFailure('blocked chat target', function () use ($target, $userA, $runId): void {
            $this->chatCreate(
                new GetOrCreatePrivateConversationRequest([
                    'request_id' => $runId . '-blocked-chat',
                    'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
                    'target' => $target,
                ]),
                $userA,
                $runId . '-blocked-chat',
            );
        }, [
            ErrorCode::CHAT_FORBIDDEN_BY_PRIVACY->value,
            ErrorCode::SOCIAL_USER_BLOCKED->value,
            // ContentService also fails closed when the target author's post
            // is hidden by the same bidirectional block. Preserve that
            // upstream authorization denial instead of weakening access just
            // to normalize the trailer at this probe boundary.
            ErrorCode::PERSONA_FORBIDDEN->value,
        ], [StatusCode::PERMISSION_DENIED]);
        $this->unblock($userA, $userB, $runId . '-unblock');

        $deleted = $this->call(
            'content',
            'api-gateway',
            $this->gatewayTokens['content'],
            '/content.v1.ContentService/DeletePost',
            new DeletePostRequest(['post_id' => $postId, 'persona_id' => '999999', 'user_id' => '999999']),
            DeletePostResponse::class,
            $userB,
            $runId . '-delete-post',
        );
        $this->assert($deleted->getSuccess(), 'Content post cleanup must succeed through its RPC');
        $this->expectFailure('deleted chat target', function () use ($target, $userA, $runId): void {
            $this->chatCreate(
                new GetOrCreatePrivateConversationRequest([
                    'request_id' => $runId . '-deleted-chat',
                    'identity_mode' => IdentityMode::IDENTITY_MODE_MAIN,
                    'target' => $target,
                ]),
                $userA,
                $runId . '-deleted-chat',
            );
        }, [ErrorCode::CONTENT_COMMENT_NOT_FOUND->value], [StatusCode::NOT_FOUND]);

        echo json_encode([
            'ok' => true,
            'run_id' => $runId,
            'post_id' => $postId,
            'conversation_id' => $mainId,
            'message_id' => (string)$message->getMessageId(),
            'message_seq' => (string)$message->getSeq(),
            'checks' => [
                'content_alias_snapshot',
                'chat_scope_ref_resolution',
                'chat_idempotency_and_identity_fixation',
                'message_persistence_and_idempotency',
                'cross_scope_and_unknown_ref_rejection',
                'bidirectional_block_send_read_rejection',
                'deleted_target_rejection',
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    }

    private function chatCreate(GetOrCreatePrivateConversationRequest $request, string $userId, string $requestId): PublicConversation
    {
        /** @var GetOrCreatePrivateConversationResponse $response */
        $response = $this->call(
            'chat',
            'api-gateway',
            $this->gatewayTokens['chat'],
            '/chat.v1.ChatService/GetOrCreatePrivateConversation',
            $request,
            GetOrCreatePrivateConversationResponse::class,
            $userId,
            $requestId,
            5000,
        );
        $conversation = $response->getConversation();
        if (!$conversation instanceof PublicConversation) {
            throw new RuntimeException('ChatService did not return a conversation');
        }

        return $conversation;
    }

    private function sendMessage(string $userId, string $conversationId, string $clientId, string $content): PublicMessage
    {
        /** @var SendMessageResponse $response */
        $response = $this->call(
            'message',
            'api-gateway',
            $this->gatewayTokens['message'],
            '/message.v1.MessageService/SendMessage',
            new PublicSendMessageRequest([
                'client_message_id' => $clientId,
                'conversation_id' => $conversationId,
                'type' => 1,
                'content' => $content,
            ]),
            SendMessageResponse::class,
            $userId,
            $clientId,
            5000,
        );
        $message = $response->getMessage();
        if (!$message instanceof PublicMessage) {
            throw new RuntimeException('MessageService did not return a message');
        }

        return $message;
    }

    private function syncMessages(string $userId, string $conversationId, string $spoofBodyUserId, string $requestId): SyncMessagesResponse
    {
        /** @var SyncMessagesResponse $response */
        $response = $this->call(
            'message',
            'api-gateway',
            $this->gatewayTokens['message'],
            '/message.v1.MessageService/SyncMessages',
            new SyncMessagesRequest([
                'user_id' => $spoofBodyUserId,
                'conversation_id' => $conversationId,
                'after_seq' => '0',
                'limit' => 50,
            ]),
            SyncMessagesResponse::class,
            $userId,
            $requestId,
            5000,
        );

        return $response;
    }

    private function getMessages(string $userId, string $conversationId, string $requestId): GetMessagesResponse
    {
        /** @var GetMessagesResponse $response */
        $response = $this->call(
            'message',
            'api-gateway',
            $this->gatewayTokens['message'],
            '/message.v1.MessageService/GetMessages',
            new GetMessagesRequest([
                'conversation_id' => $conversationId,
                'pagination' => new PaginationRequest(['page_size' => 50]),
            ]),
            GetMessagesResponse::class,
            $userId,
            $requestId,
            5000,
        );

        return $response;
    }

    private function block(string $actorUserId, string $targetUserId, string $requestId): void
    {
        /** @var BlockResponse $response */
        $response = $this->call(
            'social',
            'api-gateway',
            $this->gatewayTokens['social'],
            '/social.v1.SocialService/Block',
            new BlockRequest(['blocker_user_id' => $actorUserId, 'blocked_user_id' => $targetUserId]),
            BlockResponse::class,
            $actorUserId,
            $requestId,
            5000,
        );
        $this->assert($response->getSuccess(), 'Social block must succeed through RPC');
    }

    private function unblock(string $actorUserId, string $targetUserId, string $requestId): void
    {
        /** @var UnblockResponse $response */
        $response = $this->call(
            'social',
            'api-gateway',
            $this->gatewayTokens['social'],
            '/social.v1.SocialService/Unblock',
            new UnblockRequest(['blocker_user_id' => $actorUserId, 'blocked_user_id' => $targetUserId]),
            UnblockResponse::class,
            $actorUserId,
            $requestId,
            5000,
        );
        $this->assert($response->getSuccess(), 'Social unblock must succeed through RPC');
    }

    private function author(PublicConversation $conversation, int $identityMode): \Contracts\Gen\Common\V1\PublicAuthor
    {
        foreach ($conversation->getMembers() as $member) {
            if (!$member instanceof PublicConversationMember) {
                continue;
            }
            $author = $member->getAuthor();
            if ($author !== null && (int)$author->getIdentityMode() === $identityMode) {
                return $author;
            }
        }

        throw new RuntimeException('conversation member identity was not returned');
    }

    /**
     * @param callable():void $operation
     * @param list<int> $expectedAppCodes
     * @param list<int> $expectedGrpcStatuses
     */
    private function expectFailure(
        string $label,
        callable $operation,
        array $expectedAppCodes,
        array $expectedGrpcStatuses,
    ): void
    {
        try {
            $operation();
        } catch (GrpcCallException $e) {
            if ($e->appErrorCode === null
                || !in_array($e->appErrorCode, $expectedAppCodes, true)
                || !in_array($e->grpcStatus, $expectedGrpcStatuses, true)
            ) {
                throw new RuntimeException(sprintf(
                    'Unexpected RPC failure for %s: app=%s grpc=%d details=%s',
                    $label,
                    $e->appErrorCode === null ? 'none' : (string)$e->appErrorCode,
                    $e->grpcStatus,
                    $e->getMessage(),
                ), 0, $e);
            }
            fwrite(STDERR, sprintf(
                "expected failure %-30s app=%d grpc=%d\n",
                $label,
                $e->appErrorCode,
                $e->grpcStatus,
            ));

            return;
        }

        throw new RuntimeException('Expected RPC rejection did not occur: ' . $label);
    }

    /**
     * @template T of Message
     * @param class-string<T> $responseClass
     * @return T
     */
    private function call(
        string $service,
        string $caller,
        string $token,
        string $method,
        Message $request,
        string $responseClass,
        string $userId,
        string $requestId,
        int $timeoutMs = 1500,
    ): Message {
        $previousServiceName = getenv('SERVICE_NAME');
        putenv('SERVICE_NAME=' . $caller);
        try {
            $client = new UnaryGrpcClient($this->targets[$service], $token);

            return $client->call(
                $method,
                $request,
                $responseClass,
                $timeoutMs,
                [
                    'x-user-id' => [$userId],
                    'x-request-id' => [$requestId],
                    'x-trace-id' => [$requestId],
                ],
            );
        } finally {
            if ($previousServiceName === false) {
                putenv('SERVICE_NAME');
            } else {
                putenv('SERVICE_NAME=' . $previousServiceName);
            }
        }
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

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

try {
    (new ChatMessageRpcProbe())->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'chat_message_rpc_probe failed: ' . $e::class . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
