<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Grpc;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\Common\V1\PaginationRequest;
use Contracts\Gen\Message\V1\GetMessagesRequest;
use Contracts\Gen\Message\V1\GetMessagesResponse;
use Contracts\Gen\Message\V1\PublicMessage;
use Contracts\Gen\Message\V1\PublicSendMessageRequest;
use Contracts\Gen\Message\V1\SendMessageResponse;
use Contracts\Gen\Message\V1\SyncMessagesRequest;
use Contracts\Gen\Message\V1\SyncMessagesResponse;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use RuntimeException;
use const Grpc\STATUS_DEADLINE_EXCEEDED;
use const Grpc\STATUS_INVALID_ARGUMENT;
use const Grpc\STATUS_NOT_FOUND;
use const Grpc\STATUS_PERMISSION_DENIED;

final class MessageServiceClient
{
    private ?UnaryGrpcClient $client;
    public function __construct(private readonly ?string $target = null, private readonly int $timeoutMs = 700, private readonly ?string $serviceToken = null, ?UnaryGrpcClient $client = null) { $this->client = $client; }

    public function send(string $conversationId, string $clientMessageId, int $type, string $content): array
    {
        $response = $this->call('/message.v1.MessageService/SendMessage', new PublicSendMessageRequest([
            'client_message_id' => $clientMessageId, 'conversation_id' => $conversationId,
            'type' => $type, 'content' => $content,
        ]), SendMessageResponse::class);
        return ['message' => $this->message($response->getMessage())];
    }

    public function sync(string $conversationId, string $afterSeq, int $limit): array
    {
        $response = $this->call('/message.v1.MessageService/SyncMessages', new SyncMessagesRequest([
            'conversation_id' => $conversationId, 'after_seq' => $afterSeq, 'limit' => $limit,
        ]), SyncMessagesResponse::class);
        return [
            'messages' => array_map(fn (PublicMessage $item): array => $this->message($item), iterator_to_array($response->getMessages())),
            'has_more' => $response->getHasMore(), 'latest_seq' => (string)$response->getLatestSeq(),
        ];
    }

    public function list(string $conversationId, int $pageSize, string $cursor): array
    {
        $response = $this->call('/message.v1.MessageService/GetMessages', new GetMessagesRequest([
            'conversation_id' => $conversationId,
            'pagination' => new PaginationRequest(['page_size' => $pageSize, 'cursor' => $cursor]),
        ]), GetMessagesResponse::class);
        $pagination = $response->getPagination();
        return [
            'messages' => array_map(fn (PublicMessage $item): array => $this->message($item), iterator_to_array($response->getMessages())),
            'pagination' => ['next_cursor' => $pagination?->getNextCursor() ?? '', 'has_more' => $pagination?->getHasMore() ?? false],
        ];
    }

    /**
     * @template T of Message
     * @param class-string<T> $responseClass
     * @return T
     * @throws \Exception
     */
    private function call(string $method, Message $request, string $responseClass): Message
    {
        $client = $this->client ??= new UnaryGrpcClient($this->target ?: config('gateway.grpc.message.target', 'message-service:50056'), $this->serviceToken);
        try { return $client->call($method, $request, $responseClass, $this->timeoutMs); }
        catch (GrpcCallException $e) {
            $code = $e->appErrorCode ?? match ($e->grpcStatus) {
                STATUS_DEADLINE_EXCEEDED => ErrorCode::RPC_TIMEOUT->value,
                STATUS_NOT_FOUND => ErrorCode::CHAT_CONVERSATION_NOT_FOUND->value,
                STATUS_PERMISSION_DENIED => ErrorCode::CHAT_NOT_A_MEMBER->value,
                STATUS_INVALID_ARGUMENT => ErrorCode::INVALID_ARGUMENT->value,
                default => ErrorCode::SERVICE_UNAVAILABLE->value,
            };
            throw new RuntimeException(in_array($code, [ErrorCode::RPC_TIMEOUT->value, ErrorCode::SERVICE_UNAVAILABLE->value], true) ? ErrorCode::from($code)->message() : $e->getMessage(), $code, $e);
        }
    }

    /** @return array<string,mixed> */
    private function message(?PublicMessage $message): array
    {
        if ($message === null) { return []; }
        $author = $message->getAuthor();
        return [
            'message_id' => (string)$message->getMessageId(), 'conversation_id' => (string)$message->getConversationId(),
            'seq' => (string)$message->getSeq(), 'client_message_id' => $message->getClientMessageId(),
            'type' => $message->getType(), 'content' => $message->getContent(), 'created_at' => $message->getCreatedAt(),
            'author' => $author === null ? null : [
                'identity_mode' => $author->getIdentityMode(), 'persona_id' => (string)$author->getPersonaId(),
                'display_name' => $author->getDisplayName(), 'avatar_key' => $author->getAvatarKey(),
                'anonymous_ref' => $author->getAnonymousRef(), 'profile_accessible' => $author->getProfileAccessible(),
            ],
        ];
    }
}
