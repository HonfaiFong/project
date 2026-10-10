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

    /** 提供 bindToken 时，消费验证码和持久化已验证会话须原子完成。 */
    public function verify(
        string $phone,
        string $code,
        VerificationScene $scene,
        string $contextId,
        ?string $bindToken = null
    ): void;
}
