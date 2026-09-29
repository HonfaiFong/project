<?php

declare(strict_types=1);

namespace Services\UserService\Domain\Exception;

use Contracts\Errors\ErrorCode;
use RuntimeException;
use Throwable;

final class DuplicateUserException extends RuntimeException
{
    public function __construct(
        string $message = 'User already exists',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            $message,
            $code !== 0 ? $code : ErrorCode::USER_ALREADY_EXISTS->value,
            $previous
        );
    }
}
