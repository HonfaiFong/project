<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

use RuntimeException;
use Throwable;

class GrpcCallException extends RuntimeException
{
    public function __construct(
        public readonly int $grpcStatus,
        public readonly ?int $appErrorCode,
        string $details,
        ?Throwable $previous = null,
    ) {
        parent::__construct($details, $appErrorCode ?? $grpcStatus, $previous);
    }
}
