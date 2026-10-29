<?php

declare(strict_types=1);

namespace Services\MessageService\Domain\Repository;

use Packages\GrpcCommon\OperationBudget;

interface MessageRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function findBySenderClientId(string $senderUserId, string $clientMessageId, ?OperationBudget $budget = null): ?array;

    /** @param array<string,mixed> $existing @param array<string,mixed> $request */
    public function idempotencyMatches(array $existing, array $request, ?OperationBudget $budget = null): bool;

    /** @return list<array<string,mixed>> */
    public function listAfter(string $conversationId, string $afterSeq, int $limit, ?OperationBudget $budget = null): array;

    /** Returns newest messages first; cursor is an exclusive conversation seq bound. @return list<array<string,mixed>> */
    public function listPage(string $conversationId, int $limit, ?string $cursor, ?OperationBudget $budget = null): array;

    public function latestSequence(string $conversationId, ?OperationBudget $budget = null): string;
}
