<?php

declare(strict_types=1);

namespace Packages\Outbox;

final readonly class OutboxRecord
{
    public function __construct(
        public string $eventId,
        public string $aggregateType,
        public string $aggregateId,
        public string $eventType,
        public int $eventVersion,
        public array $payload,
        public string $eventKey,
        public string $traceId,
        public int $status = 0, // 0 = 待发布，1 = 已发布，2 = 已领取（处理中）
        public ?string $availableAt = null,
        public ?string $publishedAt = null,
        public ?string $createdAt = null,
        public ?string $id = null,
    ) {
    }
}
