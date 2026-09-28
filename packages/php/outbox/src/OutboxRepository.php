<?php

declare(strict_types=1);

namespace Packages\Outbox;

use PDO;
use Packages\IdGenerator\Snowflake;

final class OutboxRepository
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
     * @return array<OutboxRecord>
     */
    public function fetchPending(int $limit = 50): array
    {
        $now = gmdate('Y-m-d H:i:s.v');
        $stmt = $this->pdo->prepare(
            'SELECT id, event_id, aggregate_type, aggregate_id, event_type, event_version, payload, status, available_at, published_at, created_at
             FROM outbox_events
             WHERE status = 0 AND available_at <= :now
             ORDER BY id ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':now', $now);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $records = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $records[] = new OutboxRecord(
                eventId: (string)$row['event_id'],
                aggregateType: (string)$row['aggregate_type'],
                aggregateId: (string)$row['aggregate_id'],
                eventType: (string)$row['event_type'],
                eventVersion: (int)$row['event_version'],
                payload: json_decode((string)$row['payload'], true) ?: [],
                status: (int)$row['status'],
                availableAt: (string)$row['available_at'],
                publishedAt: $row['published_at'] ? (string)$row['published_at'] : null,
                createdAt: (string)$row['created_at'],
                id: (string)$row['id'],
            );
        }

        return $records;
    }

    public function markPublished(string $id): void
    {
        $now = gmdate('Y-m-d H:i:s.v');
        $stmt = $this->pdo->prepare('UPDATE outbox_events SET status = 1, published_at = :now WHERE id = :id');
        $stmt->execute(['now' => $now, 'id' => $id]);
    }

    public function markFailed(string $id, int $retryDelaySeconds = 10): void
    {
        $nextAvailable = gmdate('Y-m-d H:i:s.v', time() + $retryDelaySeconds);
        $stmt = $this->pdo->prepare('UPDATE outbox_events SET available_at = :available WHERE id = :id');
        $stmt->execute(['available' => $nextAvailable, 'id' => $id]);
    }
}
