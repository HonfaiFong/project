<?php

declare(strict_types=1);

namespace Services\MessageService\Infrastructure;

use Services\MessageService\Domain\IdGenerator;

final class RandomIdGenerator implements IdGenerator
{
    public function nextId(): string
    {
        return (string)random_int(1, PHP_INT_MAX);
    }
}
