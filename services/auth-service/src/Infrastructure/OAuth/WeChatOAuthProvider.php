<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\OAuth;

use Contracts\Errors\ErrorCode;
use RuntimeException;
use Services\AuthService\Domain\Auth\ExternalIdentity;
use Services\AuthService\Domain\Auth\SocialAuthProviderInterface;
use Services\AuthService\Domain\Auth\SocialProvider;

final class WeChatOAuthProvider implements SocialAuthProviderInterface
{
    private const TOKEN_ENDPOINT = 'https://api.weixin.qq.com/sns/oauth2/access_token';

    public function __construct(
        private readonly OAuthHttpClientInterface $httpClient,
        private readonly string $appId,
        private readonly string $appSecret,
        private readonly string $identityNamespace = 'wechat-main',
    ) {
        if ($this->appId === '' || $this->appSecret === '') {
            throw new RuntimeException(
                'WeChat social login is not configured',
                ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value
            );
        }
    }

    public function provider(): SocialProvider
    {
        return SocialProvider::WECHAT;
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

        $body = $this->httpClient->get(self::TOKEN_ENDPOINT, [
            'appid'      => $this->appId,
            'secret'     => $this->appSecret,
            'code'       => $code,
            'grant_type' => 'authorization_code',
        ]);
        $payload = $this->decodeJson($body);
        $errorCode = (int)($payload['errcode'] ?? 0);
        if ($errorCode !== 0) {
            throw new RuntimeException(
                in_array($errorCode, [40029, 40163], true)
                    ? ErrorCode::AUTH_SOCIAL_CODE_INVALID->message()
                    : ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->message(),
                in_array($errorCode, [40029, 40163], true)
                    ? ErrorCode::AUTH_SOCIAL_CODE_INVALID->value
                    : ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value
            );
        }

        $openId = trim((string)($payload['openid'] ?? ''));
        if ($openId === '') {
            throw new RuntimeException(
                ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->message(),
                ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value
            );
        }

        $unionId = trim((string)($payload['unionid'] ?? ''));

        return new ExternalIdentity(
            provider: SocialProvider::WECHAT,
            providerAppId: $this->appId,
            identityNamespace: $this->identityNamespace,
            openId: $openId,
            unionId: $unionId !== '' ? $unionId : null,
        );
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $body): array
    {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
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
