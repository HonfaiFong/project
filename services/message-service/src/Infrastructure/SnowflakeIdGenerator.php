<?php

declare(strict_types=1);

namespace Services\MessageService\Infrastructure;

use Packages\IdGenerator\Snowflake;
use Services\MessageService\Domain\IdGenerator;

final readonly class SnowflakeIdGenerator implements IdGenerator
{
    public function __construct(private Snowflake $snowflake)
    {
    }

    public function nextId(): string
    {
        return $this->snowflake->nextId();
    }
}
