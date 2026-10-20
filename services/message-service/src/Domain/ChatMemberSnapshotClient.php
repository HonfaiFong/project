<?php

declare(strict_types=1);

namespace Services\MessageService\Domain;

use Packages\GrpcCommon\OperationBudget;

/** Internal ChatService authorization/snapshot RPC. */
interface ChatMemberSnapshotClient
{
    /** @return array{allowed:bool,denial_reason:string,member?:array<string,mixed>,peer_user_id?:string} */
    public function getConversationMemberSnapshot(
        string $trustedUserId,
        string $conversationId,
        ?OperationBudget $budget = null,
    ): array;
}
