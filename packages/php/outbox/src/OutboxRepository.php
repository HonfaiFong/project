<?php

declare(strict_types=1);

namespace Packages\Outbox;

use PDO;
use Packages\IdGenerator\Snowflake;
use Throwable;

final class OutboxRepository implements EventOutboxInterface, OutboxRelayStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?Snowflake $snowflake = null
    ) {
    }

    public function recordEvent(
        string $eventId,
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        int $eventVersion,
        array $payload
    ): string {
        $id = $this->snowflake ? $this->snowflake->nextId() : (string)(time() * 1000 + random_int(100, 999));
        $now = gmdate('Y-m-d H:i:s.v');
        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = $this->pdo->prepare(
            'INSERT INTO outbox_events 
             (id, event_id, aggregate_type, aggregate_id, event_type, event_version, payload, status, available_at, created_at)
             VALUES (:id, :event_id, :aggregate_type, :aggregate_id, :event_type, :event_version, :payload, 0, :available_at, :created_at)'
        );

        $stmt->execute([
            'id'             => $id,
            'event_id'       => $eventId,
            'aggregate_type' => $aggregateType,
            'aggregate_id'   => $aggregateId,
            'event_type'     => $eventType,
            'event_version'  => $eventVersion,
            'payload'        => $jsonPayload,
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
     *
     * Statuses: 0 = pending, 1 = published, 2 = claimed (in-flight).
     *
     * @return array<OutboxRecord>
     */
    public function claimPending(int $limit = 50): array
    {
        $now = gmdate('Y-m-d H:i:s.v');
        $claimed = [];

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, event_id, aggregate_type, aggregate_id, event_type, event_version, payload, status, available_at, published_at, created_at
                 FROM outbox_events AS candidate
                 WHERE status = 0 AND available_at <= :now
                   AND NOT EXISTS (
                     SELECT 1 FROM outbox_events AS earlier
                     WHERE earlier.aggregate_type = candidate.aggregate_type
                       AND earlier.aggregate_id = candidate.aggregate_id
                       AND earlier.id < candidate.id AND earlier.status <> 1
                   )
                 ORDER BY candidate.id ASC
                 LIMIT :limit
                 FOR UPDATE SKIP LOCKED'
            );
            $stmt->bindValue(':now', $now);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $ids = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ids[] = (string)$row['id'];
                $claimed[] = new OutboxRecord(
                    eventId: (string)$row['event_id'],
                    aggregateType: (string)$row['aggregate_type'],
                    aggregateId: (string)$row['aggregate_id'],
                    eventType: (string)$row['event_type'],
                    eventVersion: (int)$row['event_version'],
                    payload: json_decode((string)$row['payload'], true) ?: [],
                    status: 2,
                    availableAt: (string)$row['available_at'],
                    publishedAt: $row['published_at'] ? (string)$row['published_at'] : null,
                    createdAt: (string)$row['created_at'],
                    id: (string)$row['id'],
                );
            }

            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $update = $this->pdo->prepare(
                    "UPDATE outbox_events SET status = 2, claimed_at = '{$now}' WHERE id IN ({$placeholders}) AND status = 0"
                );
                $update->execute($ids);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $claimed;
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
        $stmt = $this->pdo->prepare(
            "UPDATE outbox_events SET status = 0, claimed_at = NULL WHERE status = 2 AND claimed_at IS NOT NULL AND claimed_at < '{$cutoff}'"
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function markPublished(string $id): void
    {
        $now = gmdate('Y-m-d H:i:s.v');
        $stmt = $this->pdo->prepare(
            "UPDATE outbox_events SET status = 1, published_at = '{$now}', claimed_at = NULL WHERE id = :id AND status = 2"
        );
        $stmt->execute(['id' => $id]);
    }

    public function markFailed(string $id, int $retryDelaySeconds = 10): void
    {
        $nextAvailable = gmdate('Y-m-d H:i:s.v', time() + $retryDelaySeconds);
        $stmt = $this->pdo->prepare(
            "UPDATE outbox_events SET status = 0, claimed_at = NULL, available_at = '{$nextAvailable}' WHERE id = :id AND status = 2"
        );
        $stmt->execute(['id' => $id]);
    }
}
