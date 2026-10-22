<?php

declare(strict_types=1);

namespace Services\ChatService\Domain;

use Packages\GrpcCommon\OperationBudget;

interface MessageSequenceReader
{
    public function latestSequence(string $conversationId, OperationBudget $budget): string;
}
