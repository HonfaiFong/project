<?php

declare(strict_types=1);

namespace Services\ChatService\Domain\Exception;

use RuntimeException;

final class ChatException extends RuntimeException
{
    public static function invalid(string $message): self
    {
        return new self($message, 9001002);
    }

    public static function notFound(string $message = 'conversation not found'): self
    {
        return new self($message, 5001001);
    }

    public static function notMember(string $message = 'user is not a member of conversation'): self
    {
        return new self($message, 5001002);
    }

    public static function forbidden(string $message = 'chat cannot be initiated due to privacy or block status'): self
    {
        return new self($message, 5001003);
    }
}
