<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Grpc;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\Chat\V1\AnonymousChatTarget;
use Contracts\Gen\Chat\V1\GetConversationRequest;
use Contracts\Gen\Chat\V1\GetConversationResponse;
use Contracts\Gen\Chat\V1\GetOrCreatePrivateConversationRequest;
use Contracts\Gen\Chat\V1\GetOrCreatePrivateConversationResponse;
use Contracts\Gen\Chat\V1\ListConversationsRequest;
use Contracts\Gen\Chat\V1\ListConversationsResponse;
use Contracts\Gen\Chat\V1\MarkReadRequest;
use Contracts\Gen\Chat\V1\MarkReadResponse;
use Contracts\Gen\Chat\V1\PublicConversation;
use Contracts\Gen\Chat\V1\PublicConversationMember;
use Contracts\Gen\Chat\V1\PublicChatTarget;
use Contracts\Gen\Chat\V1\CheckCanMessageRequest;
use Contracts\Gen\Chat\V1\CheckCanMessageResponse;
use Contracts\Gen\Common\V1\PaginationRequest;
use Contracts\Gen\Common\V1\PublicAuthor;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use RuntimeException;
use const Grpc\STATUS_DEADLINE_EXCEEDED;
use const Grpc\STATUS_INVALID_ARGUMENT;
use const Grpc\STATUS_NOT_FOUND;
use const Grpc\STATUS_PERMISSION_DENIED;

final class ChatServiceClient
{
    private ?UnaryGrpcClient $client;
    public function __construct(private readonly ?string $target = null, private readonly int $timeoutMs = 700, private readonly ?string $serviceToken = null, ?UnaryGrpcClient $client = null) { $this->client = $client; }

    /** @param array<string,mixed> $target */
    public function getOrCreate(array $fields, array $target): array
    {
        $targetMessage = isset($target['anonymous_ref'])
            ? new PublicChatTarget(['anonymous' => new AnonymousChatTarget([
                'scope_type' => (string)($target['scope_type'] ?? ''),
                'scope_id' => (string)($target['scope_id'] ?? ''),
                'anonymous_ref' => (string)$target['anonymous_ref'],
            ])])
            : new PublicChatTarget(['main_persona_id' => (string)($target['main_persona_id'] ?? '0')]);
        $response = $this->call('/chat.v1.ChatService/GetOrCreatePrivateConversation', new GetOrCreatePrivateConversationRequest([
            'request_id' => (string)($fields['request_id'] ?? ''),
            'user_id' => (string)($fields['user_id'] ?? '0'),
            'persona_id' => (string)($fields['persona_id'] ?? '0'),
            'identity_mode' => (int)($fields['identity_mode'] ?? 1),
            'alias_display_name' => (string)($fields['alias_display_name'] ?? ''),
            'target' => $targetMessage,
        ]), GetOrCreatePrivateConversationResponse::class);
        return ['conversation' => $this->conversation($response->getConversation())];
    }

    public function get(string $conversationId): array
    {
        $response = $this->call('/chat.v1.ChatService/GetConversation', new GetConversationRequest(['conversation_id' => $conversationId]), GetConversationResponse::class);
        return ['conversation' => $this->conversation($response->getConversation())];
    }

    public function list(int $pageSize, string $cursor): array
    {
        $response = $this->call('/chat.v1.ChatService/ListConversations', new ListConversationsRequest([
            'pagination' => new PaginationRequest(['page_size' => $pageSize, 'cursor' => $cursor]),
        ]), ListConversationsResponse::class);
        $pagination = $response->getPagination();
        return [
            'conversations' => array_map(fn (PublicConversation $item): array => $this->conversation($item), iterator_to_array($response->getConversations())),
            'pagination' => ['next_cursor' => $pagination?->getNextCursor() ?? '', 'has_more' => $pagination?->getHasMore() ?? false],
        ];
    }

    public function checkCanMessage(string $recipientUserId): array
    {
        $response = $this->call('/chat.v1.ChatService/CheckCanMessage', new CheckCanMessageRequest(['recipient_user_id' => $recipientUserId]), CheckCanMessageResponse::class);
        return ['allowed' => $response->getAllowed(), 'reason' => $response->getReason()];
    }

    public function markRead(string $conversationId, string $seq): array
    {
        $response = $this->call('/chat.v1.ChatService/MarkRead', new MarkReadRequest(['conversation_id' => $conversationId, 'seq' => $seq]), MarkReadResponse::class);
        return ['success' => $response->getSuccess()];
    }

    /**
     * @template T of Message
     * @param class-string<T> $responseClass
     * @return T
     */
    private function call(string $method, Message $request, string $responseClass): Message
    {
        $client = $this->client ??= new UnaryGrpcClient($this->target ?: config('gateway.grpc.chat.target', 'chat-service:50055'), $this->serviceToken);
        try { return $client->call($method, $request, $responseClass, $this->timeoutMs); }
        catch (GrpcCallException $e) {
            $code = $e->appErrorCode ?? match ($e->grpcStatus) {
                STATUS_DEADLINE_EXCEEDED => ErrorCode::RPC_TIMEOUT->value,
                STATUS_NOT_FOUND => ErrorCode::CHAT_CONVERSATION_NOT_FOUND->value,
                STATUS_PERMISSION_DENIED => ErrorCode::CHAT_FORBIDDEN_BY_PRIVACY->value,
                STATUS_INVALID_ARGUMENT => ErrorCode::INVALID_ARGUMENT->value,
                default => ErrorCode::SERVICE_UNAVAILABLE->value,
            };
            throw new RuntimeException(in_array($code, [ErrorCode::RPC_TIMEOUT->value, ErrorCode::SERVICE_UNAVAILABLE->value], true) ? ErrorCode::from($code)->message() : $e->getMessage(), $code, $e);
        }
    }

    /** @return array<string,mixed> */
    private function conversation(?PublicConversation $conversation): array
    {
        if ($conversation === null) { return []; }
        $members = [];
        foreach ($conversation->getMembers() as $member) {
            /** @var PublicConversationMember $member */
            $members[] = [
                'author' => $this->author($member->getAuthor()),
                'last_read_seq' => (string)$member->getLastReadSeq(),
                'joined_at' => $member->getJoinedAt(),
            ];
        }
        return [
            'conversation_id' => (string)$conversation->getConversationId(),
            'type' => $conversation->getType(),
            'last_message_id' => (string)$conversation->getLastMessageId(),
            'last_message_at' => $conversation->getLastMessageAt(),
            'members' => $members,
            'created_at' => $conversation->getCreatedAt(),
        ];
    }

    /** @return array<string,mixed>|null */
    private function author(?PublicAuthor $author): ?array
    {
        return $author === null ? null : [
            'identity_mode' => $author->getIdentityMode(), 'persona_id' => (string)$author->getPersonaId(),
            'display_name' => $author->getDisplayName(), 'avatar_key' => $author->getAvatarKey(),
            'anonymous_ref' => $author->getAnonymousRef(), 'profile_accessible' => $author->getProfileAccessible(),
        ];
    }
}
