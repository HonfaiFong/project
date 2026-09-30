<?php

declare(strict_types=1);

namespace Services\AuthService\Domain\Auth;

enum SocialProvider: string
{
    case WECHAT = 'wechat';
    case QQ = 'qq';
}
