<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\OAuth;

use Contracts\Errors\ErrorCode;
use RuntimeException;
use Services\AuthService\Domain\Auth\ExternalIdentity;
use Services\AuthService\Domain\Auth\SocialAuthProviderInterface;
use Services\AuthService\Domain\Auth\SocialProvider;

final class QqOAuthProvider implements SocialAuthProviderInterface
{
    private const TOKEN_ENDPOINT = 'https://graph.qq.com/oauth2.0/token';
    private const OPEN_ID_ENDPOINT = 'https://graph.qq.com/oauth2.0/me';

    public function __construct(
        private readonly OAuthHttpClientInterface $httpClient,
        private readonly string $appId,
        private readonly string $appKey,
        private readonly string $redirectUri = '',
        private readonly string $identityNamespace = 'qq-main',
    ) {
        if ($this->appId === '' || $this->appKey === '') {
            throw new RuntimeException(
                'QQ social login is not configured',
                ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value
            );
        }
    }

    public function provider(): SocialProvider
    {
        return SocialProvider::QQ;
    }

    public function authenticate(string $code): ExternalIdentity
    {
        $code = trim($code);
        if ($code === '') {
            throw new RuntimeException(
                ErrorCode::AUTH_SOCIAL_CODE_INVALID->message(),
                ErrorCode::AUTH_SOCIAL_CODE_INVALID->value
            );
        }

        $tokenPayload = $this->parseQueryString($this->httpClient->get(self::TOKEN_ENDPOINT, [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->appId,
            'client_secret' => $this->appKey,
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri,
        ]));

        if (isset($tokenPayload['error']) || trim((string)($tokenPayload['access_token'] ?? '')) === '') {
            throw new RuntimeException(
                ErrorCode::AUTH_SOCIAL_CODE_INVALID->message(),
                ErrorCode::AUTH_SOCIAL_CODE_INVALID->value
            );
        }

        $openIdBody = $this->httpClient->get(self::OPEN_ID_ENDPOINT, [
            'access_token' => (string)$tokenPayload['access_token'],
        ]);
        $identityPayload = $this->parseCallbackJson($openIdBody);

        if (isset($identityPayload['error'])
            || (string)($identityPayload['client_id'] ?? '') !== $this->appId
        ) {
            throw new RuntimeException(
                ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->message(),
                ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value
            );
        }

        $openId = trim((string)($identityPayload['openid'] ?? ''));
        if ($openId === '') {
            throw new RuntimeException(
                ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->message(),
                ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value
            );
        }

        return new ExternalIdentity(
            provider: SocialProvider::QQ,
            providerAppId: $this->appId,
            identityNamespace: $this->identityNamespace,
            openId: $openId,
        );
    }

    /** @return array<string, string> */
    private function parseQueryString(string $body): array
    {
        $payload = [];
        parse_str(trim($body), $payload);

        return array_map(static fn (mixed $value): string => is_scalar($value) ? (string)$value : '', $payload);
    }

    /** @return array<string, mixed> */
    private function parseCallbackJson(string $body): array
    {
        $body = trim($body);
        if (!preg_match('/\\Acallback\\s*\\(\\s*(\\{.*\\})\\s*\\)\\s*;?\\z/sD', $body, $matches)) {
            throw new RuntimeException(
                ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->message(),
                ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value
            );
        }

        try {
            $payload = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException(
                ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->message(),
                ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value,
                $e
            );
        }

        if (!is_array($payload)) {
            throw new RuntimeException(
                ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->message(),
                ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value
            );
        }

        return $payload;
    }
}
