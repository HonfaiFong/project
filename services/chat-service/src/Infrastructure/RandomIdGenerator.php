<?php

declare(strict_types=1);

namespace Services\ChatService\Infrastructure;

use Random\RandomException;
use Services\ChatService\Domain\IdGenerator;

final class RandomIdGenerator implements IdGenerator
{
    /** @throws RandomException */
    public function nextId(): string
    {
        // Snowflake is used by production workers; this fallback keeps the
        // application service usable in isolated SQLite tests.
        return (string)random_int(1, PHP_INT_MAX);
    }
}
