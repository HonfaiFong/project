<?php

declare(strict_types=1);

namespace Services\MessageService\Domain\Exception;

use RuntimeException;

final class MessageException extends RuntimeException
{
    public static function invalid(string $message): self
    {
        return new self($message, 9001002);
    }

    public static function notFound(string $message = 'message not found'): self
    {
        return new self($message, 6001001);
    }

    public static function duplicate(string $message = 'duplicate client message id'): self
    {
        return new self($message, 6001002);
    }

    public static function forbidden(string $message = 'conversation access denied'): self
    {
        return new self($message, 5001003);
    }
}
