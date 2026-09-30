<?php

declare(strict_types=1);

namespace Services\AuthService\Domain\Phone;

enum VerificationScene: string
{
    case SOCIAL_BIND_PHONE = 'social_bind_phone';
}
