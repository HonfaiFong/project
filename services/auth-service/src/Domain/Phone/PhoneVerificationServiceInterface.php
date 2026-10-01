<?php

declare(strict_types=1);

namespace Services\AuthService\Domain\Phone;

interface PhoneVerificationServiceInterface
{
    /**
     * @param array{ip?: ?string, device_uuid?: ?string} $context
     */
    public function send(
        string $phone,
        VerificationScene $scene,
        string $contextId,
        array $context = []
    ): void;

    /** With bindToken, consumption and verified-session persistence must be atomic. */
    public function verify(
        string $phone,
        string $code,
        VerificationScene $scene,
        string $contextId,
        ?string $bindToken = null
    ): void;
}
