<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Errors\ErrorCode;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Services\AuthService\Domain\Auth\SocialProvider;
use Services\AuthService\Infrastructure\OAuth\GuzzleOAuthHttpClient;
use Services\AuthService\Infrastructure\OAuth\OAuthHttpClientInterface;
use Services\AuthService\Infrastructure\OAuth\QqOAuthProvider;
use Services\AuthService\Infrastructure\OAuth\WeChatOAuthProvider;
use Tests\TestCase;

final class SocialOAuthHttpFake implements OAuthHttpClientInterface
{
    /** @var array<array{url: string, query: array<string, string>}> */
    public array $requests = [];

    /** @param array<string> $responses */
    public function __construct(private array $responses)
    {
    }

    public function get(string $url, array $query = []): string
    {
        $this->requests[] = ['url' => $url, 'query' => $query];

        return array_shift($this->responses) ?? '';
    }
}

final class SocialOAuthProviderTest extends TestCase
{
    public function testWeChatUsesUnionIdWhenAvailableAndDoesNotExposeTokens(): void
    {
        $http = new SocialOAuthHttpFake([
            json_encode([
                'access_token' => 'wechat-access-token',
                'openid'       => 'wechat-openid',
                'unionid'      => 'wechat-unionid',
            ], JSON_THROW_ON_ERROR),
        ]);
        $provider = new WeChatOAuthProvider($http, 'wechat-app', 'wechat-secret');

        $identity = $provider->authenticate('server-code');

        $this->assertSame(SocialProvider::WECHAT, $identity->provider);
        $this->assertSame('wechat-openid', $identity->openId);
        $this->assertSame('wechat-unionid', $identity->subjectId());
        $this->assertSame('unionid', $identity->subjectType());
        $this->assertSame('wechat-app', $http->requests[0]['query']['appid']);
        $this->assertArrayNotHasKey('access_token', $http->requests[0]['query']);
    }

    public function testWeChatFallsBackToOpenId(): void
    {
        $http = new SocialOAuthHttpFake([
            '{"access_token":"token","openid":"wechat-openid"}',
        ]);
        $identity = (new WeChatOAuthProvider($http, 'wechat-app', 'wechat-secret'))->authenticate('code');

        $this->assertSame('openid', $identity->subjectType());
        $this->assertSame('wechat-openid', $identity->subjectId());
        $this->assertNull($identity->unionId);
    }

    public function testWeChatInvalidCodeErrorMapsToAuthenticationError(): void
    {
        $http = new SocialOAuthHttpFake(['{"errcode":40029,"errmsg":"invalid code"}']);

        try {
            (new WeChatOAuthProvider($http, 'wechat-app', 'wechat-secret'))->authenticate('code');
            $this->fail('Expected invalid code error');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_CODE_INVALID->value, $e->getCode());
        }
    }

    public function testWeChatMalformedJsonAndMissingOpenIdAreRejected(): void
    {
        try {
            (new WeChatOAuthProvider(new SocialOAuthHttpFake(['not-json']), 'app', 'secret'))->authenticate('code');
            $this->fail('Expected malformed JSON error');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value, $e->getCode());
        }

        try {
            (new WeChatOAuthProvider(new SocialOAuthHttpFake(['{"access_token":"token"}']), 'app', 'secret'))
                ->authenticate('code');
            $this->fail('Expected missing openid error');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value, $e->getCode());
        }
    }

    public function testQqParsesServerTokenAndStrictCallbackPayload(): void
    {
        $http = new SocialOAuthHttpFake([
            'access_token=qq-access-token&expires_in=7776000',
            'callback({"client_id":"qq-app","openid":"qq-openid"});',
        ]);
        $identity = (new QqOAuthProvider($http, 'qq-app', 'qq-key', 'https://example.test/callback'))
            ->authenticate('server-code');

        $this->assertSame(SocialProvider::QQ, $identity->provider);
        $this->assertSame('qq-openid', $identity->subjectId());
        $this->assertSame('openid', $identity->subjectType());
        $this->assertSame('server-code', $http->requests[0]['query']['code']);
        $this->assertSame('qq-access-token', $http->requests[1]['query']['access_token']);
    }

    public function testQqRejectsClientIdMismatchAndMalformedCallback(): void
    {
        try {
            (new QqOAuthProvider(new SocialOAuthHttpFake([
                'access_token=token',
                'callback({"client_id":"other-app","openid":"openid"});',
            ]), 'qq-app', 'qq-key'))->authenticate('code');
            $this->fail('Expected client id mismatch');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value, $e->getCode());
        }

        try {
            (new QqOAuthProvider(new SocialOAuthHttpFake([
                'access_token=token',
                '{"client_id":"qq-app","openid":"openid"}',
            ]), 'qq-app', 'qq-key'))->authenticate('code');
            $this->fail('Expected malformed callback');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value, $e->getCode());
        }
    }

    public function testQqTokenEndpointErrorAndMissingOpenIdAreRejected(): void
    {
        try {
            (new QqOAuthProvider(new SocialOAuthHttpFake(['error=invalid_grant']), 'qq-app', 'qq-key'))
                ->authenticate('code');
            $this->fail('Expected token error');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_CODE_INVALID->value, $e->getCode());
        }

        try {
            (new QqOAuthProvider(new SocialOAuthHttpFake([
                'access_token=token',
                'callback({"client_id":"qq-app"});',
            ]), 'qq-app', 'qq-key'))->authenticate('code');
            $this->fail('Expected missing openid');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value, $e->getCode());
        }
    }

    public function testOAuthHttpClientMapsNetworkAndHttpFailuresWithoutRetrying(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('request')
            ->with('GET', 'https://example.test/oauth', $this->arrayHasKey('connect_timeout'))
            ->willThrowException(new ConnectException('timeout', new Request('GET', 'https://example.test/oauth')));

        try {
            (new GuzzleOAuthHttpClient($client))->get('https://example.test/oauth');
            $this->fail('Expected upstream network error');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value, $e->getCode());
        }

        $failedResponseClient = $this->createMock(ClientInterface::class);
        $failedResponseClient->expects($this->once())
            ->method('request')
            ->willReturn(new Response(503, [], 'upstream unavailable'));

        try {
            (new GuzzleOAuthHttpClient($failedResponseClient))->get('https://example.test/oauth');
            $this->fail('Expected upstream status error');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value, $e->getCode());
        }
    }

    public function testOAuthHttpClientAllowsLocalFakeHttpOnlyWhenExplicitlyEnabled(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('request')
            ->with('GET', 'http://fake-oauth:8081/oauth', $this->arrayHasKey('connect_timeout'))
            ->willReturn(new Response(200, [], '{}'));

        $previousEnvironment = getenv('APP_ENV');
        putenv('APP_ENV=testing');
        try {
            $this->assertSame('{}', (new GuzzleOAuthHttpClient(
                $client,
                allowInsecureHttp: true,
            ))->get('http://fake-oauth:8081/oauth'));
        } finally {
            $previousEnvironment === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previousEnvironment);
        }
    }

    public function testOAuthHttpClientNeverAllowsFakeHttpInProduction(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->never())->method('request');

        $previousEnvironment = getenv('APP_ENV');
        putenv('APP_ENV=production');
        try {
            $this->expectExceptionCode(ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value);
            (new GuzzleOAuthHttpClient($client, allowInsecureHttp: true))->get('http://fake-oauth:8081/oauth');
        } finally {
            $previousEnvironment === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previousEnvironment);
        }
    }

    public function testProvidersRejectEmptyCodesBeforeCallingUpstream(): void
    {
        $wechatHttp = new SocialOAuthHttpFake([]);
        try {
            (new WeChatOAuthProvider($wechatHttp, 'app', 'secret'))->authenticate(' ');
            $this->fail('Expected invalid WeChat code');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_CODE_INVALID->value, $e->getCode());
        }

        $qqHttp = new SocialOAuthHttpFake([]);
        try {
            (new QqOAuthProvider($qqHttp, 'app', 'key'))->authenticate(' ');
            $this->fail('Expected invalid QQ code');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_CODE_INVALID->value, $e->getCode());
        }

        $this->assertCount(0, $wechatHttp->requests);
        $this->assertCount(0, $qqHttp->requests);
    }

    public function testProvidersRejectMissingApplicationSecrets(): void
    {
        try {
            new WeChatOAuthProvider(new SocialOAuthHttpFake([]), 'app', '');
            $this->fail('Expected missing WeChat secret error');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value, $e->getCode());
        }

        try {
            new QqOAuthProvider(new SocialOAuthHttpFake([]), '', 'key');
            $this->fail('Expected missing QQ app id error');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR->value, $e->getCode());
        }
    }
}
