<?php

declare(strict_types=1);

namespace Services\AuthService\Domain\Auth;

final readonly class ExternalIdentity
{
    public function __construct(
        public SocialProvider $provider,
        public string $providerAppId,
        public string $identityNamespace,
        public string $openId,
        public ?string $unionId = null,
    ) {
    }

    public function subjectType(): string
    {
        return filled($this->unionId) ? 'unionid' : 'openid';
    }

    public function subjectId(): string
    {
        return filled($this->unionId) ? $this->unionId : $this->openId;
    }
}
