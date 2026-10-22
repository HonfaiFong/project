<?php

declare(strict_types=1);

namespace Services\MessageService\Infrastructure\Mongo;

use MongoDB\BSON\Int64;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Driver\Session;
use MongoDB\Operation\FindOneAndUpdate;
use Packages\Database\MongoConnectionFactory;
use Packages\GrpcCommon\OperationBudget;
use Services\MessageService\Domain\Repository\MessageWriteTransactionInterface;

final class MongoMessageWriteTransaction implements MessageWriteTransactionInterface
{
    private readonly \MongoDB\Collection $messages;
    private readonly \MongoDB\Collection $sequences;
    private readonly \MongoDB\Collection $outbox;

    public function __construct(
        Client $client,
        string $database,
        private readonly Session $session,
        private readonly MessageDocumentMapper $mapper = new MessageDocumentMapper(),
        private readonly ?OperationBudget $budget = null,
        private readonly int $commitTimeoutMs = 0,
    ) {
        $this->messages = $client->selectCollection($database, 'messages', [
            'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
        ]);
        $this->sequences = $client->selectCollection($database, 'conversation_sequences', [
            'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
        ]);
        $this->outbox = $client->selectCollection($database, 'outbox_events', [
            'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
        ]);
    }

    public function findBySenderClientId(string $senderUserId, string $clientMessageId): ?array
    {
        $this->checkBudget();
        $document = $this->messages->findOne([
            'sender_user_id' => $this->mapper->int64($senderUserId, 'sender_user_id', false),
            'client_message_id' => $clientMessageId,
        ], [
            'session' => $this->session,
            'maxTimeMS' => MongoConnectionFactory::rpcQueryTimeoutMs($this->budget, $this->commitTimeoutMs),
            'collation' => ['locale' => 'simple'],
        ]);
        return $document === null ? null : $this->mapper->toDomain($document);
    }

    public function nextSequence(string $conversationId, string $now): string
    {
        $this->checkBudget();
        $updated = $this->sequences->findOneAndUpdate(
            ['_id' => $this->mapper->int64($conversationId, 'conversation_id', false)],
            ['$inc' => ['last_seq' => new Int64('1')], '$set' => ['updated_at' => $this->utcDate($now)]],
            [
                'upsert' => true,
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                'session' => $this->session,
                'maxTimeMS' => MongoConnectionFactory::rpcQueryTimeoutMs($this->budget, $this->commitTimeoutMs),
            ],
        );
        $document = $updated === null ? null : (is_array($updated) ? $updated : get_object_vars($updated));
        if ($document === null || !isset($document['last_seq'])) {
            throw new \RuntimeException('MongoDB conversation sequence update returned no document');
        }
        return $this->mapper->decimal($document['last_seq'], 'last_seq');
    }

    public function insert(array $message): void
    {
        $this->checkBudget();
        $this->messages->insertOne($this->mapper->toDocument($message), ['session' => $this->session]);
    }

    public function recordMessageCreated(
        string $eventId,
        string $conversationId,
        string $messageId,
        string $sequence,
        array $payload,
        string $traceId,
        string $now,
    ): void {
        $this->checkBudget();
        $this->outbox->insertOne([
            '_id' => $eventId,
            'conversation_id' => $this->mapper->int64($conversationId, 'conversation_id', false),
            'message_id' => $this->mapper->int64($messageId, 'message_id', false),
            'message_seq' => $this->mapper->int64($sequence, 'seq', false),
            'aggregate_type' => 'conversation',
            'aggregate_id' => $conversationId,
            'event_key' => $conversationId,
            'event_type' => 'message.created.v1',
            'event_version' => 1,
            'trace_id' => $traceId,
            'payload' => $payload,
            'status' => 0,
            'available_at' => $this->utcDate($now),
            'claimed_at' => null,
            'lease_until' => null,
            'claim_token' => null,
            'published_at' => null,
            'created_at' => $this->utcDate($now),
            'attempt_count' => 0,
            'schema_version' => 1,
        ], ['session' => $this->session]);
    }

    private function utcDate(string $value): UTCDateTime
    {
        return new UTCDateTime(new \DateTimeImmutable($value, new \DateTimeZone('UTC')));
    }

    private function checkBudget(): void
    {
        $this->budget?->remainingMilliseconds(MongoConnectionFactory::rpcReserveMs() + $this->commitTimeoutMs);
    }
}
