<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Phone;

use Contracts\Errors\ErrorCode;
use RuntimeException;
use Services\AuthService\Domain\Phone\SmsSenderInterface;
use Services\AuthService\Domain\Phone\VerificationScene;

final class UnavailableSmsSender implements SmsSenderInterface
{
    public function sendVerificationCode(string $phone, string $code, VerificationScene $scene): void
    {
        throw new RuntimeException(
            'SMS provider is not configured',
            ErrorCode::SERVICE_UNAVAILABLE->value
        );
    }
}
