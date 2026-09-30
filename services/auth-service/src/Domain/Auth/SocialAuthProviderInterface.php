<?php

declare(strict_types=1);

namespace Services\AuthService\Domain\Auth;

interface SocialAuthProviderInterface
{
    public function provider(): SocialProvider;

    public function authenticate(string $code): ExternalIdentity;
}
