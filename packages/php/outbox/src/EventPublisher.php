<?php

declare(strict_types=1);

namespace Packages\Outbox;

interface EventPublisher
{
    public function publish(OutboxRecord $event): void;
}
