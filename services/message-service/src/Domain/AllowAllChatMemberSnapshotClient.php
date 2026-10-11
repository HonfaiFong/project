<?php

declare(strict_types=1);

namespace Services\MessageService\Domain;

final class AllowAllChatMemberSnapshotClient implements ChatMemberSnapshotClient
{
    public function getConversationMemberSnapshot(string $trustedUserId, string $conversationId): array
    {
        return ['allowed' => false, 'denial_reason' => 'ChatService client is not configured'];
    }
}
