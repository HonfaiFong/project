<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Phone;

use Services\AuthService\Domain\Phone\SmsSenderInterface;
use Services\AuthService\Domain\Phone\VerificationScene;

final class DevSmsSender implements SmsSenderInterface
{
    public function sendVerificationCode(string $phone, string $code, VerificationScene $scene): void
    {
        // 向 RedisPhoneVerificationService 提供本地验证码，使开发客户端使用固定验证码，无须记录到日志。
    }
}
