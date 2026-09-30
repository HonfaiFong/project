<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Phone;

use Services\AuthService\Domain\Phone\SmsSenderInterface;
use Services\AuthService\Domain\Phone\VerificationScene;

final class DevSmsSender implements SmsSenderInterface
{
    public function sendVerificationCode(string $phone, string $code, VerificationScene $scene): void
    {
        // The local verification code is supplied to RedisPhoneVerificationService so that
        // development clients can use a deterministic code without logging it.
    }
}
