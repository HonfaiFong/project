<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\OAuth;

use Contracts\Errors\ErrorCode;
use RuntimeException;
use Services\AuthService\Domain\Auth\SocialAuthProviderInterface;
use Services\AuthService\Domain\Auth\SocialProvider;

final class SocialAuthProviderRegistry
{
    /** @var array<string, SocialAuthProviderInterface> */
    private array $providers = [];

    /** @param iterable<SocialAuthProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->provider()->value] = $provider;
        }
    }

    public function get(SocialProvider $provider): SocialAuthProviderInterface
    {
        $implementation = $this->providers[$provider->value] ?? null;
        if ($implementation === null) {
            throw new RuntimeException(
                ErrorCode::AUTH_SOCIAL_PROVIDER_UNSUPPORTED->message(),
                ErrorCode::AUTH_SOCIAL_PROVIDER_UNSUPPORTED->value
            );
        }

        return $implementation;
    }
}
