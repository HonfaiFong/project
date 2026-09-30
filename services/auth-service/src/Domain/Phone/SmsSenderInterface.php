<?php

declare(strict_types=1);

namespace Services\AuthService\Domain\Phone;

interface SmsSenderInterface
{
    public function sendVerificationCode(
        string $phone,
        string $code,
        VerificationScene $scene
    ): void;
}
