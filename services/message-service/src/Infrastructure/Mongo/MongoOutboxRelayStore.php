<?php

declare(strict_types=1);

namespace Services\MessageService\Infrastructure\Mongo;

use DateTimeImmutable;
use DateTimeZone;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\WriteConcern;
use Packages\Outbox\OutboxClaim;
use Packages\Outbox\OutboxRecord;
use Packages\Outbox\OutboxRelayStore;
use Throwable;

final class MongoOutboxRelayStore implements OutboxRelayStore
{
    private readonly Collection $outbox;
    /** @var array{available_at:mixed,created_at:mixed,_id:mixed}|null */
    private ?array $scanAfter = null;

    public function __construct(private readonly Client $client, string $database = 'message_db')
    {
        $this->outbox = $client->selectCollection($database, 'outbox_events', [
            'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
        ]);
    }

    public function releaseStaleClaims(): int
    {
        $result = $this->outbox->updateMany(
            ['status' => 2, '$expr' => ['$lt' => ['$lease_until', '$$NOW']]],
            [
                ['$set' => ['status' => 0]],
                ['$unset' => ['claimed_at', 'lease_until', 'claim_token']],
            ],
        );
        return $result->getModifiedCount();
    }

    public function claimPending(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $claims = [];
        $pageSize = max(50, $limit);
        $scanBudget = 2000;
        for ($scanned = 0; $scanned < $scanBudget && count($claims) < $limit; $scanned += $pageSize) {
            $filter = ['status' => 0, '$expr' => ['$lte' => ['$available_at', '$$NOW']]];
            if ($this->scanAfter !== null) {
                $after = $this->scanAfter;
                $filter['$or'] = [
                    ['available_at' => ['$gt' => $after['available_at']]],
                    ['available_at' => $after['available_at'], 'created_at' => ['$gt' => $after['created_at']]],
                    ['available_at' => $after['available_at'], 'created_at' => $after['created_at'], '_id' => ['$gt' => $after['_id']]],
                ];
            }
            $candidates = $this->outbox->find(
                $filter,
                [
                    'sort' => ['available_at' => 1, 'created_at' => 1, '_id' => 1],
                    'limit' => $pageSize,
                    'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
                ],
            );
            $page = iterator_to_array($candidates, false);
            if ($page === []) {
                $this->scanAfter = null;
                break;
            }
            foreach ($page as $candidate) {
                // Persist progress across relay ticks. Blocked conversations
                // must not hide unrelated events beyond this tick's scan cap.
                $this->scanAfter = array_intersect_key($candidate, array_flip(['available_at', 'created_at', '_id']));
                $claim = $this->claimCandidate($candidate);
                if ($claim !== null) {
                    $claims[] = $claim;
                    if (count($claims) >= $limit) {
                        break;
                    }
                }
            }
            if (count($page) < $pageSize) {
                $this->scanAfter = null;
                break;
            }
        }
        return $claims;
    }

    public function markPublished(OutboxClaim $claim): bool
    {
        $result = $this->outbox->updateOne(
            ['_id' => $claim->record->eventId, 'status' => 2, 'claim_token' => $claim->claimToken],
            [
                ['$set' => ['status' => 1, 'published_at' => '$$NOW']],
                ['$unset' => ['claimed_at', 'lease_until', 'claim_token']],
            ],
        );
        return $result->getModifiedCount() === 1;
    }

    public function markFailed(OutboxClaim $claim, int $retryDelaySeconds = 10): bool
    {
        $delay = max(0, min(86400, $retryDelaySeconds));
        $result = $this->outbox->updateOne(
            ['_id' => $claim->record->eventId, 'status' => 2, 'claim_token' => $claim->claimToken],
            [
                ['$set' => [
                    'status' => 0,
                    'available_at' => ['$dateAdd' => ['startDate' => '$$NOW', 'unit' => 'second', 'amount' => $delay]],
                ]],
                ['$unset' => ['claimed_at', 'lease_until', 'claim_token']],
            ],
        );
        return $result->getModifiedCount() === 1;
    }

    public function backlogDepth(): int
    {
        return $this->outbox->countDocuments(['status' => ['$in' => [0, 2]]]);
    }

    /** @param array<string,mixed> $candidate */
    private function claimCandidate(array $candidate): ?OutboxClaim
    {
        $session = $this->client->startSession();
        $active = false;
        try {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                try {
                    $session->startTransaction([
                        'readPreference' => new ReadPreference(ReadPreference::PRIMARY),
                        'readConcern' => new ReadConcern(ReadConcern::SNAPSHOT),
                        'writeConcern' => new WriteConcern(WriteConcern::MAJORITY, 5000, true),
                    ]);
                    $active = true;
                    $predecessor = $this->outbox->findOne([
                        'conversation_id' => $candidate['conversation_id'],
                        'message_seq' => ['$lt' => $candidate['message_seq']],
                        'status' => ['$in' => [0, 2]],
                    ], ['session' => $session]);
                    if ($predecessor !== null) {
                        $session->abortTransaction();
                        $active = false;
                        return null;
                    }

                    $token = bin2hex(random_bytes(24));
                    $updated = $this->outbox->updateOne(
                        ['_id' => $candidate['_id'], 'status' => 0, '$expr' => ['$lte' => ['$available_at', '$$NOW']]],
                        [[ '$set' => [
                            'status' => 2,
                            'claim_token' => $token,
                            'claimed_at' => '$$NOW',
                            'lease_until' => ['$dateAdd' => ['startDate' => '$$NOW', 'unit' => 'second', 'amount' => 90]],
                            'attempt_count' => ['$add' => [['$ifNull' => ['$attempt_count', 0]], 1]],
                        ]]],
                        ['session' => $session],
                    );
                    if ($updated->getModifiedCount() !== 1) {
                        $session->abortTransaction();
                        $active = false;
                        return null;
                    }
                    $document = $this->outbox->findOne(['_id' => $candidate['_id'], 'claim_token' => $token], ['session' => $session]);
                    $session->commitTransaction();
                    $active = false;
                    if ($document === null) {
                        return null;
                    }
                    $row = is_array($document) ? $document : get_object_vars($document);
                    return new OutboxClaim($this->record($row), $token, $this->dateString($row['lease_until']));
                } catch (Throwable $exception) {
                    if ($active) {
                        try { $session->abortTransaction(); } catch (Throwable) {}
                        $active = false;
                    }
                    if ($attempt < 2 && $this->hasErrorLabel($exception, 'TransientTransactionError')) {
                        continue;
                    }
                    throw $exception;
                }
            }
            return null;
        } finally {
            $session->endSession();
        }
    }

    /** @param array<string,mixed> $row */
    private function record(array $row): OutboxRecord
    {
        return new OutboxRecord(
            eventId: (string)$row['_id'],
            aggregateType: (string)$row['aggregate_type'],
            aggregateId: (string)$row['aggregate_id'],
            eventType: (string)$row['event_type'],
            eventVersion: (int)$row['event_version'],
            payload: (array)$row['payload'],
            eventKey: (string)$row['event_key'],
            traceId: (string)$row['trace_id'],
            status: (int)$row['status'],
            availableAt: isset($row['available_at']) ? $this->dateString($row['available_at']) : null,
            publishedAt: isset($row['published_at']) ? $this->dateString($row['published_at']) : null,
            createdAt: $this->dateString($row['created_at']),
            id: (string)$row['_id'],
        );
    }

    private function dateString(mixed $value): string
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        }
        return (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function hasErrorLabel(Throwable $exception, string $label): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (method_exists($current, 'hasErrorLabel') && $current->hasErrorLabel($label)) {
                return true;
            }
        }
        return false;
    }
}
