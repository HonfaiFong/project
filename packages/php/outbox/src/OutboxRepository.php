<?php

declare(strict_types=1);

namespace Packages\Outbox;

use PDO;
use Exception;
use Throwable;
use Packages\IdGenerator\Snowflake;
use Packages\Database\EloquentConnection;
use Packages\Outbox\Model\OutboxEvent;
use Illuminate\Database\Query\Builder;

final readonly class OutboxRepository implements EventOutboxInterface, OutboxRelayStore
{
    private OutboxEvent $model;

    public function __construct(
        PDO $pdo,
        private ?Snowflake $snowflake = null
    ) {
        $this->model = EloquentConnection::model(OutboxEvent::class, $pdo);
    }

    /** @throws Exception If secure random generation, JSON encoding, or persistence fails. */
    public function recordEvent(
        string $eventId,
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        int $eventVersion,
        array $payload,
        string $eventKey,
        string $traceId
    ): string {
        $id = $this->snowflake ? $this->snowflake->nextId() : (string)(time() * 1000 + random_int(100, 999));
        $now = gmdate('Y-m-d H:i:s.v');
        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->model->newQuery()->create([
            'id'             => $id,
            'event_id'       => $eventId,
            'aggregate_type' => $aggregateType,
            'aggregate_id'   => $aggregateId,
            'event_key'      => $eventKey,
            'trace_id'       => $traceId,
            'event_type'     => $eventType,
            'event_version'  => $eventVersion,
            'payload'        => $jsonPayload,
            'status'         => 0,
            'available_at'   => $now,
            'created_at'     => $now,
        ]);

        return $id;
    }

    /**
     * Atomically claims pending events for this worker.
     *
     * Runs a SELECT ... FOR UPDATE SKIP LOCKED pass inside a transaction and
     * flips the claimed rows to status=2 before committing, so concurrent
     * workers never receive the same row. Crashed workers leave rows claimed;
     * releaseStaleClaims() returns them to the queue.
     * Earlier unpublished rows with the same broker event_key block the claim,
     * even when their aggregate types and IDs differ.
     *
     * Statuses: 0 = pending, 1 = published, 2 = claimed (in-flight).
     *
     * @throws Throwable If the claim transaction fails.
     * @return array<OutboxRecord>
     */
    public function claimPending(int $limit = 50): array
    {
        return $this->model->getConnection()->transaction(function () use ($limit): array {
            $query = $this->model->newQuery();
            $query->from('outbox_events as candidate')
                ->select('candidate.*')
                ->where('candidate.status', 0)
                ->where('candidate.available_at', '<=', gmdate('Y-m-d H:i:s.v'))
                ->whereNotExists(function (Builder $query): void {
                    $query->select('earlier.id')->from('outbox_events as earlier')
                        ->whereColumn('earlier.event_key', 'candidate.event_key')
                        ->whereColumn('earlier.id', '<', 'candidate.id')
                        ->where('earlier.status', '<>', 1);
                })
                ->orderBy('candidate.id')->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED');
            $rows = $query->get();

            if ($rows->isNotEmpty()) {
                $this->model->newQuery()->whereIn('id', $rows->modelKeys())->where('status', 0)
                    ->update(['status' => 2, 'claimed_at' => gmdate('Y-m-d H:i:s.v')]);
            }

            return $rows->map(static function (OutboxEvent $event): OutboxRecord {
                $row = $event->getAttributes();

                return new OutboxRecord(
                    eventId: (string)$row['event_id'],
                    aggregateType: (string)$row['aggregate_type'],
                    aggregateId: (string)$row['aggregate_id'],
                    eventType: (string)$row['event_type'],
                    eventVersion: (int)$row['event_version'],
                    payload: json_decode((string)$row['payload'], true) ?: [],
                    eventKey: (string)$row['event_key'],
                    traceId: (string)$row['trace_id'],
                    status: 2,
                    availableAt: (string)$row['available_at'],
                    publishedAt: $row['published_at'] ? (string)$row['published_at'] : null,
                    createdAt: (string)$row['created_at'],
                    id: (string)$row['id'],
                );
            })->all();
        });
    }

    /**
     * Returns rows claimed longer than $olderThanSeconds ago to the pending
     * queue (crash recovery for workers that died mid-publish).
     *
     * @return int number of released rows
     */
    public function releaseStaleClaims(int $olderThanSeconds = 300): int
    {
        $cutoff = gmdate('Y-m-d H:i:s.v', time() - $olderThanSeconds);
        return $this->model->newQuery()->where('status', 2)->whereNotNull('claimed_at')
            ->where('claimed_at', '<', $cutoff)->update(['status' => 0, 'claimed_at' => null]);
    }

    public function markPublished(string $id): void
    {
        $now = gmdate('Y-m-d H:i:s.v');
        $this->model->newQuery()->whereKey($id)->where('status', 2)
            ->update(['status' => 1, 'published_at' => $now, 'claimed_at' => null]);
    }

    public function markFailed(string $id, int $retryDelaySeconds = 10): void
    {
        $nextAvailable = gmdate('Y-m-d H:i:s.v', time() + $retryDelaySeconds);
        $this->model->newQuery()->whereKey($id)->where('status', 2)
            ->update(['status' => 0, 'claimed_at' => null, 'available_at' => $nextAvailable]);
    }
}
