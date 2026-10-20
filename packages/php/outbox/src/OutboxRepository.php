<?php

declare(strict_types=1);

namespace Packages\Outbox;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Illuminate\Database\Query\Builder;
use Packages\Database\EloquentConnection;
use Packages\IdGenerator\Snowflake;
use Packages\Outbox\Model\OutboxEvent;
use PDO;
use Throwable;

final readonly class OutboxRepository implements EventOutboxInterface, OutboxRelayStore
{
    private OutboxEvent $model;

    public function __construct(PDO $pdo, private ?Snowflake $snowflake = null)
    {
        $this->model = EloquentConnection::model(OutboxEvent::class, $pdo);
    }

    /** @throws Exception */
    public function recordEvent(
        string $eventId,
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        int $eventVersion,
        array $payload,
        string $eventKey,
        string $traceId,
    ): string {
        $id = $this->snowflake ? $this->snowflake->nextId() : (string)(time() * 1000 + random_int(100, 999));
        $now = gmdate('Y-m-d H:i:s.v');
        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->model->newQuery()->create([
            'id' => $id,
            'event_id' => $eventId,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_key' => $eventKey,
            'trace_id' => $traceId,
            'event_type' => $eventType,
            'event_version' => $eventVersion,
            'payload' => $jsonPayload,
            'status' => 0,
            'available_at' => $now,
            'created_at' => $now,
            'attempt_count' => 0,
        ]);
        return $id;
    }

    /** @return list<OutboxClaim> @throws Throwable */
    public function claimPending(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        return $this->model->getConnection()->transaction(function () use ($limit): array {
            $query = $this->model->newQuery()->from('outbox_events as candidate')
                ->select('candidate.*')->where('candidate.status', 0)
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
            $claims = [];
            $connection = $this->model->getConnection();
            foreach ($rows as $event) {
                $rawId = $event->id ?? null;
                if ($rawId === null) {
                    continue;
                }
                $id = (string) $rawId;
                $token = bin2hex(random_bytes(24));
                $leaseUntil = $connection->getDriverName() === 'pgsql'
                    ? $connection->raw("CURRENT_TIMESTAMP + INTERVAL '90 seconds'")
                    : gmdate('Y-m-d H:i:s.v', time() + 90);
                $updated = $this->model->newQuery()->where('id', $id)->where('status', 0)->update([
                    'status' => 2,
                    'claim_token' => $token,
                    'claimed_at' => $connection->raw('CURRENT_TIMESTAMP'),
                    'lease_until' => $leaseUntil,
                    'attempt_count' => $connection->raw('attempt_count + 1'),
                ]);
                if ($updated !== 1) {
                    continue;
                }
                $claimed = $this->model->newQuery()->where('id', $id)->where('claim_token', $token)->firstOrFail();
                $row = $claimed->getAttributes();
                $claims[] = new OutboxClaim($this->record($row), $token, $this->dateString($row['lease_until']));
            }
            return $claims;
        });
    }

    public function releaseStaleClaims(): int
    {
        return $this->model->newQuery()->where('status', 2)->whereNotNull('lease_until')
            ->where('lease_until', '<', $this->model->getConnection()->raw('CURRENT_TIMESTAMP'))
            ->update(['status' => 0, 'claimed_at' => null, 'lease_until' => null, 'claim_token' => null]);
    }

    public function backlogDepth(): int
    {
        return (int)$this->model->newQuery()->whereIn('status', [0, 2])->count();
    }

    public function markPublished(OutboxClaim $claim): bool
    {
        return $this->model->newQuery()->where('event_id', $claim->record->eventId)->where('status', 2)
            ->where('claim_token', $claim->claimToken)->update([
                'status' => 1,
                'published_at' => $this->model->getConnection()->raw('CURRENT_TIMESTAMP'),
                'claimed_at' => null,
                'lease_until' => null,
                'claim_token' => null,
            ]) === 1;
    }

    public function markFailed(OutboxClaim $claim, int $retryDelaySeconds = 10): bool
    {
        $delay = max(0, min(86400, $retryDelaySeconds));
        $connection = $this->model->getConnection();
        $availableAt = $connection->getDriverName() === 'pgsql'
            ? $connection->raw("CURRENT_TIMESTAMP + INTERVAL '{$delay} seconds'")
            : gmdate('Y-m-d H:i:s.v', time() + $delay);
        return $this->model->newQuery()->where('event_id', $claim->record->eventId)->where('status', 2)
            ->where('claim_token', $claim->claimToken)->update([
                'status' => 0,
                'claimed_at' => null,
                'lease_until' => null,
                'claim_token' => null,
                'available_at' => $availableAt,
            ]) === 1;
    }

    /** @param array<string,mixed> $row */
    private function record(array $row): OutboxRecord
    {
        return new OutboxRecord(
            eventId: (string)$row['event_id'],
            aggregateType: (string)$row['aggregate_type'],
            aggregateId: (string)$row['aggregate_id'],
            eventType: (string)$row['event_type'],
            eventVersion: (int)$row['event_version'],
            payload: json_decode((string)$row['payload'], true, flags: JSON_THROW_ON_ERROR) ?: [],
            eventKey: (string)$row['event_key'],
            traceId: (string)$row['trace_id'],
            status: 2,
            availableAt: $this->dateString($row['available_at']),
            publishedAt: isset($row['published_at']) ? $this->dateString($row['published_at']) : null,
            createdAt: $this->dateString($row['created_at']),
            id: (string)$row['id'],
        );
    }

    private function dateString(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        }
        return (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
