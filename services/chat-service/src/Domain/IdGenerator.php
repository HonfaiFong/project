<?php

declare(strict_types=1);

namespace Services\ChatService\Domain;

interface IdGenerator
{
    public function nextId(): string;
}
