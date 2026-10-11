<?php

declare(strict_types=1);

namespace Services\MessageService\Infrastructure;

use Contracts\Gen\Chat\V1\ConversationMemberSnapshot;
use Contracts\Gen\Chat\V1\GetConversationMemberSnapshotRequest;
use Contracts\Gen\Chat\V1\GetConversationMemberSnapshotResponse;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\UnaryGrpcClient;
use Services\MessageService\Domain\ChatMemberSnapshotClient;

/** Calls ChatService for the authenticated member's immutable session snapshot. */
final class GrpcChatMemberSnapshotClient implements ChatMemberSnapshotClient
{
    private ?UnaryGrpcClient $client = null;

    public function __construct(
        private readonly string $target,
        private readonly ?string $serviceToken = null,
        private readonly int $timeoutMs = 250,
        ?UnaryGrpcClient $client = null,
    ) {
        $this->client = $client;
    }

    public function getConversationMemberSnapshot(string $trustedUserId, string $conversationId): array
    {
        /** @var GetConversationMemberSnapshotResponse $response */
        $response = $this->call(
            '/chat.v1.ChatService/GetConversationMemberSnapshot',
            new GetConversationMemberSnapshotRequest([
                'conversation_id' => $conversationId,
            ]),
            GetConversationMemberSnapshotResponse::class,
            ['x-user-id' => [$trustedUserId]],
        );
        $member = $response->getMember();

        return [
            'allowed' => $response->getAllowed(),
            'denial_reason' => $response->getDenialReason(),
            'member' => $member instanceof ConversationMemberSnapshot ? [
                'user_id' => (string)$member->getUserId(),
                'persona_id' => (string)$member->getPersonaId(),
                'identity_mode' => (int)$member->getIdentityMode(),
                'display_name' => $member->getDisplayName(),
                'avatar_key' => $member->getAvatarKey(),
                'anonymous_ref' => $member->getAnonymousRef(),
                'scope_type' => $member->getScopeType(),
                'scope_id' => $member->getScopeId(),
                'profile_accessible' => $member->getProfileAccessible(),
            ] : null,
        ];
    }

    /**
     * @template T of Message
     * @param class-string<T> $responseClass
     * @param array<string, array<string>> $metadata
     * @return T
     */
    private function call(string $method, Message $request, string $responseClass, array $metadata = []): Message
    {
        $client = $this->client ??= new UnaryGrpcClient($this->target, $this->serviceToken);

        return $client->call($method, $request, $responseClass, $this->timeoutMs, $metadata);
    }
}
