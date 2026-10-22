<?php

declare(strict_types=1);

namespace Services\MessageService\Infrastructure\Mongo;

use MongoDB\BSON\Int64;
use Packages\GrpcCommon\OperationBudget;
use Packages\Database\MongoConnectionFactory;
use MongoDB\Client;
use MongoDB\Collection;
use Services\MessageService\Application\MessageContentCodec;
use Services\MessageService\Domain\Repository\MessageRepositoryInterface;

final class MongoMessageRepository implements MessageRepositoryInterface
{
    private readonly Collection $messages;

    public function __construct(
        Client $client,
        string $database = 'message_db',
        private readonly MessageDocumentMapper $mapper = new MessageDocumentMapper(),
        private readonly MessageContentCodec $codec = new MessageContentCodec(),
    ) {
        $this->messages = $client->selectCollection($database, 'messages', [
            'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
        ]);
    }

    public function findBySenderClientId(string $senderUserId, string $clientMessageId, ?OperationBudget $budget = null): ?array
    {
        $this->checkBudget($budget);
        $document = $this->messages->findOne(
            ['sender_user_id' => $this->mapper->int64($senderUserId, 'sender_user_id', false), 'client_message_id' => $clientMessageId],
            ['collation' => ['locale' => 'simple'], 'maxTimeMS' => MongoConnectionFactory::rpcQueryTimeoutMs($budget)],
        );
        return $document === null ? null : $this->mapper->toDomain($document);
    }

    public function idempotencyMatches(array $existing, array $request): bool
    {
        $existingContent = $this->codec->canonicalJson($existing['content'] ?? null);
        $requestedContent = $this->codec->canonicalJson($request['content'] ?? null);
        return (string)$existing['conversation_id'] === (string)$request['conversation_id']
            && (int)$existing['type'] === (int)$request['type']
            && (($existingContent !== null && $requestedContent !== null)
                ? $existingContent === $requestedContent
                : (string)$existing['content'] === (string)$request['content']);
    }

    public function listAfter(string $conversationId, string $afterSeq, int $limit, ?OperationBudget $budget = null): array
    {
        $this->checkBudget($budget);
        $cursor = $this->messages->find([
            'conversation_id' => $this->mapper->int64($conversationId, 'conversation_id', false),
            'seq' => ['$gt' => $this->mapper->int64($afterSeq, 'after_seq')],
        ], [
            'sort' => ['seq' => 1],
            'maxTimeMS' => MongoConnectionFactory::rpcQueryTimeoutMs($budget),
            'limit' => $limit + 1,
            'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
        ]);
        $rows = [];
        foreach ($cursor as $document) {
            $this->checkBudget($budget);
            $rows[] = $this->mapper->toDomain($document);
        }
        return $rows;
    }

    public function listPage(string $conversationId, int $limit, ?string $cursor, ?OperationBudget $budget = null): array
    {
        $this->checkBudget($budget);
        $filter = ['conversation_id' => $this->mapper->int64($conversationId, 'conversation_id', false)];
        if ($cursor !== null && $cursor !== '') {
            $filter['seq'] = ['$lt' => $this->mapper->int64($cursor, 'cursor', false)];
        }
        $rows = [];
        foreach ($this->messages->find($filter, [
            'sort' => ['seq' => -1],
            'maxTimeMS' => MongoConnectionFactory::rpcQueryTimeoutMs($budget),
            'limit' => $limit + 1,
            'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
        ]) as $document) {
            $this->checkBudget($budget);
            $rows[] = $this->mapper->toDomain($document);
        }
        return $rows;
    }

    public function latestSequence(string $conversationId, ?OperationBudget $budget = null): string
    {
        $this->checkBudget($budget);
        $document = $this->messages->findOne(
            ['conversation_id' => $this->mapper->int64($conversationId, 'conversation_id', false)],
            [
                'projection' => ['seq' => 1],
                'maxTimeMS' => MongoConnectionFactory::rpcQueryTimeoutMs($budget),
                'sort' => ['seq' => -1],
                'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
            ],
        );
        return $document === null ? '0' : $this->mapper->decimal($document['seq'], 'seq');
    }

    private function checkBudget(?OperationBudget $budget): void
    {
        $budget?->remainingMilliseconds(MongoConnectionFactory::rpcReserveMs());
    }
}
