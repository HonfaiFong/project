<?php

declare(strict_types=1);

namespace Services\MessageService\Domain;

interface IdGenerator
{
    public function nextId(): string;
}
