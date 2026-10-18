<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Errors\ErrorCode;
use Services\AuthService\Application\Service\AuthApplicationService;
use Services\AuthService\Domain\Auth\ExternalIdentity;
use Services\AuthService\Domain\Auth\SocialAuthProviderInterface;
use Services\AuthService\Domain\Auth\SocialProvider;
use Services\AuthService\Domain\Client\UserDirectoryInterface;
use Services\AuthService\Domain\Entity\Device;
use Services\AuthService\Domain\Entity\RefreshToken;
use Services\AuthService\Domain\Entity\SocialIdentity;
use Services\AuthService\Domain\Repository\AuthRepositoryInterface;
use Services\AuthService\Domain\Repository\SocialIdentityRepositoryInterface;
use Services\AuthService\Infrastructure\Auth\LoginCredentialVerifier;
use Services\AuthService\Infrastructure\OAuth\SocialAuthProviderRegistry;
use Services\AuthService\Infrastructure\Token\JwtTokenCodec;
use Services\AuthService\Infrastructure\Token\WsTicketIssuer;
use Tests\TestCase;

final class SocialAuthRepositoryFake implements AuthRepositoryInterface
{
    /** @var array<string, Device> */
    public array $devices = [];

    /** @var array<string, RefreshToken> */
    public array $tokens = [];

    public function findOrCreateDevice(
        string $userId,
        string $deviceUuid,
        string $platform,
        string $appVersion,
        ?string $pushToken,
        ?string $ip
    ): Device {
        $key = $userId . ':' . $deviceUuid;
        if (isset($this->devices[$key])) {
            return $this->devices[$key];
        }

        $device = new Device(
            id: 'device-' . (count($this->devices) + 1),
            userId: $userId,
            deviceUuid: $deviceUuid,
            platform: $platform,
            appVersion: $appVersion,
            pushToken: $pushToken,
            lastIp: $ip,
        );
        $this->devices[$key] = $device;

        return $device;
    }

    public function deviceBelongsToUser(string $userId, string $deviceId): bool
    {
        foreach ($this->devices as $device) {
            if ($device->id === $deviceId && $device->userId === $userId) {
                return true;
            }
        }

        return false;
    }

    public function createRefreshToken(
        string $userId,
        string $deviceId,
        string $tokenHash,
        string $expiresAt
    ): RefreshToken {
        $token = new RefreshToken(
            id: 'refresh-' . (count($this->tokens) + 1),
            userId: $userId,
            deviceId: $deviceId,
            tokenHash: $tokenHash,
            expiresAt: $expiresAt,
        );
        $this->tokens[$tokenHash] = $token;

        return $token;
    }

    public function rotateRefreshToken(
        string $tokenHash,
        string $newTokenHash,
        string $newExpiresAt
    ): ?RefreshToken {
        $token = $this->tokens[$tokenHash] ?? null;
        if ($token === null || !$token->isValid()) {
            return null;
        }

        unset($this->tokens[$tokenHash]);
        $this->createRefreshToken($token->userId, $token->deviceId, $newTokenHash, $newExpiresAt);

        return $token;
    }

    public function findRefreshToken(string $tokenHash): ?RefreshToken
    {
        return $this->tokens[$tokenHash] ?? null;
    }

    public function revokeRefreshToken(string $tokenHash): bool
    {
        $record = $this->tokens[$tokenHash] ?? null;
        if ($record === null || $record->revokedAt !== null) {
            return false;
        }

        $properties = get_object_vars($record);
        $properties['revokedAt'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->tokens[$tokenHash] = new RefreshToken(...$properties);

        return true;
    }
}

final class SocialUserDirectoryFake implements UserDirectoryInterface
{
    /** @var array<array<string, string>> */
    public array $calls = [];

    public int $status = self::STATUS_ACTIVE;
    public bool $loseReply = false;
    private array $registrations = [];

    public int $failuresRemaining = 0;

    /** @param array{user_id: string, is_new_user: bool} $result */
    public function __construct(public array $result)
    {
    }

    public function findOrCreateByPhone(string $phone, string $registerChannel = 'default'): array
    {
        $this->calls[] = [
            'phone'           => $phone,
            'register_channel' => $registerChannel,
        ];

        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;
            throw new \RuntimeException(
                ErrorCode::SERVICE_UNAVAILABLE->message(),
                ErrorCode::SERVICE_UNAVAILABLE->value
            );
        }

        return $this->result;
    }

    public function findOrCreateBySocialIdentity(string $registrationKey, string $registerChannel): array
    {
        $this->calls[] = ['registration_key' => $registrationKey, 'register_channel' => $registerChannel];
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;
            throw new \RuntimeException('Unavailable', ErrorCode::SERVICE_UNAVAILABLE->value);
        }
        $new = !isset($this->registrations[$registrationKey]);
        $this->registrations[$registrationKey] ??= $this->result;
        if ($this->loseReply) {
            $this->loseReply = false;
            throw new \RuntimeException('Reply lost after commit', ErrorCode::RPC_TIMEOUT->value);
        }

        return ['user_id' => $this->registrations[$registrationKey]['user_id'], 'is_new_user' => $new];
    }

    public function findById(string $userId): array
    {
        return [
            'user_id' => $userId,
            'status'  => $this->status,
        ];
    }
}

final class SocialIdentityRepositoryFake implements SocialIdentityRepositoryInterface
{
    /** @var array<string, SocialIdentity> */
    public array $identities = [];

    public function __construct(private readonly string $defaultProviderAppId = 'app-id')
    {
    }

    public function findByExternalIdentity(ExternalIdentity $identity): ?SocialIdentity
    {
        foreach ($this->identities as $stored) {
            if (
                $stored->provider === $identity->provider
                && $stored->providerAppId === $identity->providerAppId
                && (
                    ($stored->subjectType === $identity->subjectType() && $stored->subjectId === $identity->subjectId())
                    || $stored->openId === $identity->openId
                )
            ) {
                return $stored;
            }
        }

        return null;
    }

    public function reserve(ExternalIdentity $identity): SocialIdentity
    {
        $existing = $this->findByExternalIdentity($identity);
        if ($existing !== null) {
            return $existing;
        }

        $reserved = new SocialIdentity(
            id: 'identity-' . (count($this->identities) + 1),
            provider: $identity->provider,
            providerAppId: $identity->providerAppId !== '' ? $identity->providerAppId : $this->defaultProviderAppId,
            identityNamespace: $identity->identityNamespace,
            subjectType: $identity->subjectType(),
            subjectId: $identity->subjectId(),
            openId: $identity->openId,
            unionId: $identity->unionId,
            userId: null,
            status: SocialIdentity::STATUS_PENDING_PHONE,
            boundAt: null,
            lastLoginAt: null,
            createdAt: '2026-01-01 00:00:00.000',
            updatedAt: '2026-01-01 00:00:00.000',
        );
        $this->identities[$reserved->id] = $reserved;

        return $reserved;
    }

    public function findById(string $id): ?SocialIdentity
    {
        return $this->identities[$id] ?? null;
    }

    public bool $failCompletion = false;

    public function beginRegistration(string $identityId): SocialIdentity
    {
        $identity = $this->identities[$identityId];
        if ($identity->isActive()) {
            return $identity;
        }
        if ($identity->isDisabled()) {
            throw new \RuntimeException('Conflict', ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->value);
        }
        $properties = get_object_vars($identity);
        $properties['status'] = SocialIdentity::STATUS_REGISTERING;

        return $this->identities[$identityId] = new SocialIdentity(...$properties);
    }

    public function completeRegistration(string $identityId, string $userId): SocialIdentity
    {
        if ($this->failCompletion) {
            $this->failCompletion = false;
            throw new \RuntimeException('Unavailable', ErrorCode::SERVICE_UNAVAILABLE->value);
        }
        return $this->bind($identityId, $userId, SocialIdentity::STATUS_REGISTERING);
    }

    public function bindToUser(string $identityId, string $userId): SocialIdentity
    {
        return $this->bind($identityId, $userId, SocialIdentity::STATUS_PENDING);
    }

    private function bind(string $identityId, string $userId, int $expectedStatus): SocialIdentity
    {
        $identity = $this->identities[$identityId] ?? null;
        if ($identity === null) {
            throw new \RuntimeException(
                ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->message(),
                ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->value
            );
        }
        if ($identity->isActive()) {
            if ($identity->userId === $userId) {
                return $identity;
            }

            throw new \RuntimeException(
                ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->message(),
                ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->value
            );
        }

        if ($identity->status !== $expectedStatus) {
            throw new \RuntimeException('Conflict', ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->value);
        }

        foreach ($this->identities as $other) {
            if (
                $other->id !== $identityId
                && $other->isActive()
                && $other->userId === $userId
                && $other->provider === $identity->provider
            ) {
                throw new \RuntimeException(
                    ErrorCode::AUTH_SOCIAL_PROVIDER_ALREADY_BOUND->message(),
                    ErrorCode::AUTH_SOCIAL_PROVIDER_ALREADY_BOUND->value
                );
            }
        }

        $bound = new SocialIdentity(
            id: $identity->id,
            provider: $identity->provider,
            providerAppId: $identity->providerAppId,
            identityNamespace: $identity->identityNamespace,
            subjectType: $identity->subjectType,
            subjectId: $identity->subjectId,
            openId: $identity->openId,
            unionId: $identity->unionId,
            userId: $userId,
            status: SocialIdentity::STATUS_ACTIVE,
            boundAt: '2026-01-01 00:00:00.000',
            lastLoginAt: $identity->lastLoginAt,
            createdAt: $identity->createdAt,
            updatedAt: '2026-01-01 00:00:00.000',
        );
        $this->identities[$identityId] = $bound;

        return $bound;
    }

    public function touchLastLogin(string $identityId): void
    {
        $identity = $this->identities[$identityId] ?? null;
        if ($identity === null) {
            return;
        }

        $this->identities[$identityId] = new SocialIdentity(
            id: $identity->id,
            provider: $identity->provider,
            providerAppId: $identity->providerAppId,
            identityNamespace: $identity->identityNamespace,
            subjectType: $identity->subjectType,
            subjectId: $identity->subjectId,
            openId: $identity->openId,
            unionId: $identity->unionId,
            userId: $identity->userId,
            status: $identity->status,
            boundAt: $identity->boundAt,
            lastLoginAt: '2026-01-01 00:00:00.000',
            createdAt: $identity->createdAt,
            updatedAt: '2026-01-01 00:00:00.000',
        );
    }

    public function seed(SocialIdentity $identity): void
    {
        $this->identities[$identity->id] = $identity;
    }
}

final class FakeSocialAuthProvider implements SocialAuthProviderInterface
{
    /** @var array<string, true> */
    private array $redeemedCodes = [];

    public function __construct(private readonly ExternalIdentity $identity)
    {
    }

    public function provider(): SocialProvider
    {
        return $this->identity->provider;
    }

    /**
     * 沿用真实上游约定：授权码最多兑换一次。重放已失效授权码返回提供方的无效授权码错误，因此恢复流程必须使用新授权码。
     */
    public function authenticate(string $code): ExternalIdentity
    {
        if (isset($this->redeemedCodes[$code])) {
            throw new \RuntimeException(
                ErrorCode::AUTH_SOCIAL_CODE_INVALID->message(),
                ErrorCode::AUTH_SOCIAL_CODE_INVALID->value
            );
        }

        $this->redeemedCodes[$code] = true;

        return $this->identity;
    }
}

final class SocialAuthServiceTest extends TestCase
{
    public function testFakeRepositoryKeepsSameDeviceUuidSeparateAcrossUsers(): void
    {
        $repository = new SocialAuthRepositoryFake();

        $first = $repository->findOrCreateDevice('10001', 'shared-device', 'ios', '1.0.0', null, null);
        $second = $repository->findOrCreateDevice('10002', 'shared-device', 'android', '2.0.0', null, null);
        $retry = $repository->findOrCreateDevice('10001', 'shared-device', 'ios', '1.0.0', null, null);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->id, $retry->id);
        $this->assertCount(2, $repository->devices);
        $this->assertTrue($repository->deviceBelongsToUser('10001', $first->id));
        $this->assertFalse($repository->deviceBelongsToUser('10002', $first->id));
        $this->assertTrue($repository->deviceBelongsToUser('10002', $second->id));
    }

    public function testFirstWeChatAndQqLoginsRegisterWithoutPhoneOrSms(): void
    {
        foreach ([SocialProvider::WECHAT, SocialProvider::QQ] as $provider) {
            $fixture = $this->fixture($provider, ['user_id' => '10001', 'is_new_user' => true]);
            $result = $fixture['service']->socialLogin($provider, 'code', $this->device());
            $this->assertSame('authenticated', $result['state']);
            $this->assertTrue($result['login']['is_new_user']);
            $this->assertSame('10001', $result['login']['user_id']);
            $this->assertSame([['registration_key' => 'social:identity-1', 'register_channel' => $provider->value]], $fixture['userDirectory']->calls);
            $this->assertCount(1, $fixture['authRepo']->tokens);
        }
    }

    public function testRepeatedLoginReusesUserWithoutRegistrationRpc(): void
    {
        $fixture = $this->fixture(SocialProvider::WECHAT);
        $first = $fixture['service']->socialLogin(SocialProvider::WECHAT, 'code1', $this->device());
        $second = $fixture['service']->socialLogin(SocialProvider::WECHAT, 'code2', $this->device());
        $this->assertSame($first['login']['user_id'], $second['login']['user_id']);
        $this->assertFalse($second['login']['is_new_user']);
        $this->assertCount(1, $fixture['userDirectory']->calls);
    }

    public function testLoginResumesAfterUserServiceCommittedButReplyWasLost(): void
    {
        $fixture = $this->fixture(SocialProvider::WECHAT);
        $fixture['userDirectory']->loseReply = true;
        try {
            $fixture['service']->socialLogin(SocialProvider::WECHAT, 'code1', $this->device());
            $this->fail('Expected timeout');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::RPC_TIMEOUT->value, $e->getCode());
        }
        $this->assertSame(SocialIdentity::STATUS_REGISTERING, $fixture['identityRepo']->findById('identity-1')->status);
        $this->assertCount(0, $fixture['authRepo']->tokens);
        $result = $fixture['service']->socialLogin(SocialProvider::WECHAT, 'code2', $this->device());
        $this->assertSame('10001', $result['login']['user_id']);
        $this->assertSame($fixture['userDirectory']->calls[0], $fixture['userDirectory']->calls[1]);
    }

    public function testLoginResumesAfterAuthBindingFailure(): void
    {
        $fixture = $this->fixture(SocialProvider::QQ);
        $fixture['identityRepo']->failCompletion = true;
        try {
            $fixture['service']->socialLogin(SocialProvider::QQ, 'code1', $this->device());
            $this->fail('Expected binding failure');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::SERVICE_UNAVAILABLE->value, $e->getCode());
        }
        $result = $fixture['service']->socialLogin(SocialProvider::QQ, 'code2', $this->device());
        $this->assertSame('10001', $result['login']['user_id']);
        $this->assertSame($fixture['userDirectory']->calls[0], $fixture['userDirectory']->calls[1]);
    }

    public function testRedeemedCodeIsRejectedAndFreshCodeResumesTheSameIdentity(): void
    {
        $fixture = $this->fixture(SocialProvider::WECHAT);
        $fixture['userDirectory']->loseReply = true;
        try {
            $fixture['service']->socialLogin(SocialProvider::WECHAT, 'code1', $this->device());
            $this->fail('Expected timeout');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::RPC_TIMEOUT->value, $e->getCode());
        }

        // 已消耗授权码仅能使用一次：重放返回无效授权码错误，不改变注册状态。
        try {
            $fixture['service']->socialLogin(SocialProvider::WECHAT, 'code1', $this->device());
            $this->fail('Expected the replayed code to be rejected');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_CODE_INVALID->value, $e->getCode());
        }
        $this->assertSame(
            SocialIdentity::STATUS_REGISTERING,
            $fixture['identityRepo']->findById('identity-1')->status
        );

        // 同一外部身份的新授权码仅恢复同一注册一次，不产生重复用户或身份。
        $result = $fixture['service']->socialLogin(SocialProvider::WECHAT, 'code2', $this->device());
        $this->assertSame('authenticated', $result['state']);
        $this->assertSame('10001', $result['login']['user_id']);
        $this->assertCount(1, $fixture['identityRepo']->identities);
        $this->assertSame($fixture['userDirectory']->calls[0], $fixture['userDirectory']->calls[1]);
        $this->assertCount(2, $fixture['userDirectory']->calls);
    }

    public function testLoggedInUserCanBindQqThenLoginAsSameUser(): void
    {
        $fixture = $this->bindingFixture();
        $wechat = $fixture['service']->socialLogin(SocialProvider::WECHAT, 'code', $this->device());
        $bound = $fixture['service']->bindSocialIdentity(SocialProvider::QQ, 'qq-code', $wechat['login']['access_token']);
        $this->assertSame(['user_id' => '10001', 'provider' => 'qq'], $bound);
        $qq = $fixture['service']->socialLogin(SocialProvider::QQ, 'another-code', $this->device());
        $this->assertSame('10001', $qq['login']['user_id']);
        $this->assertCount(1, $fixture['userDirectory']->calls);
    }

    public function testAnotherUsersIdentityCannotBeTransferred(): void
    {
        $fixture = $this->bindingFixture();
        $login = $fixture['service']->socialLogin(SocialProvider::WECHAT, 'code', $this->device());
        $fixture['identityRepo']->seed($this->identity(SocialProvider::QQ, 'qq-openid', 'qq-owned', '10002', SocialIdentity::STATUS_ACTIVE));
        try {
            $fixture['service']->bindSocialIdentity(SocialProvider::QQ, 'code', $login['login']['access_token']);
            $this->fail('Expected conflict');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->value, $e->getCode());
        }
        $this->assertSame('10002', $fixture['identityRepo']->findById('qq-owned')->userId);
    }

    public function testRegisteringIdentityCannotBeClaimedByManualBinding(): void
    {
        $fixture = $this->bindingFixture();
        $login = $fixture['service']->socialLogin(SocialProvider::WECHAT, 'code', $this->device());
        $fixture['identityRepo']->seed($this->identity(SocialProvider::QQ, 'qq-openid', 'qq-registering', null, SocialIdentity::STATUS_REGISTERING));
        $this->expectExceptionCode(ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->value);
        $fixture['service']->bindSocialIdentity(SocialProvider::QQ, 'code', $login['login']['access_token']);
    }

    public function testBindingRejectsInvalidSessionBeforeCreatingIdentity(): void
    {
        $fixture = $this->bindingFixture();
        try {
            $fixture['service']->bindSocialIdentity(SocialProvider::QQ, 'code', 'invalid');
            $this->fail('Expected invalid session');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_UNAUTHORIZED->value, $e->getCode());
        }
        $this->assertCount(0, $fixture['identityRepo']->identities);
    }

    public function testDisabledIdentityCannotRegisterAgain(): void
    {
        $fixture = $this->fixture(SocialProvider::QQ);
        $fixture['identityRepo']->seed($this->identity(SocialProvider::QQ, 'qq-openid', 'disabled', '10001', SocialIdentity::STATUS_DISABLED));
        $this->expectExceptionCode(ErrorCode::AUTH_UNAUTHORIZED->value);
        $fixture['service']->socialLogin(SocialProvider::QQ, 'code', $this->device());
    }

    public function testBannedUserCannotLoginOrBind(): void
    {
        $fixture = $this->bindingFixture();
        $login = $fixture['service']->socialLogin(SocialProvider::WECHAT, 'code', $this->device());
        $fixture['userDirectory']->status = 0;
        try {
            $fixture['service']->socialLogin(SocialProvider::WECHAT, 'code2', $this->device());
            $this->fail('Expected banned user');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::USER_BANNED->value, $e->getCode());
        }
        $this->expectExceptionCode(ErrorCode::AUTH_UNAUTHORIZED->value);
        $fixture['service']->bindSocialIdentity(SocialProvider::QQ, 'code', $login['login']['access_token']);
    }

    public function testSocialSessionRefreshAndLogoutRemainValid(): void
    {
        $fixture = $this->fixture(SocialProvider::QQ);
        $login = $fixture['service']->socialLogin(SocialProvider::QQ, 'code', $this->device())['login'];
        $refresh = $fixture['service']->refreshToken($login['refresh_token']);
        $this->assertTrue($fixture['service']->verifyToken($refresh['access_token'])['valid']);
        $this->assertTrue($fixture['service']->logout($refresh['refresh_token']));
        $this->expectExceptionCode(ErrorCode::AUTH_TOKEN_EXPIRED->value);
        $fixture['service']->refreshToken($refresh['refresh_token']);
    }

    public function testLegacySmsBindingIsDisabled(): void
    {
        $fixture = $this->fixture(SocialProvider::QQ);
        $this->expectExceptionCode(ErrorCode::AUTH_UNAUTHORIZED->value);
        $fixture['service']->bindSocialPhone('token', '13800000000', '123456', $this->device());
    }

    private function bindingFixture(): array
    {
        return $this->fixture(SocialProvider::WECHAT, additionalIdentities: [$this->externalIdentity(SocialProvider::QQ)]);
    }

    /**
     * @param array{user_id: string, is_new_user: bool} $userResult
     * @param array<ExternalIdentity> $additionalIdentities
     * @return array{
     *     service: AuthApplicationService,
     *     authRepo: SocialAuthRepositoryFake,
     *     userDirectory: SocialUserDirectoryFake,
     *     identityRepo: SocialIdentityRepositoryFake,
     * }
     */
    private function fixture(
        SocialProvider $provider,
        array $userResult = ['user_id' => '10001', 'is_new_user' => false],
        bool $active = false,
        array $additionalIdentities = [],
    ): array {
        $identity = $this->externalIdentity($provider);
        $providers = [new FakeSocialAuthProvider($identity)];
        foreach ($additionalIdentities as $additionalIdentity) {
            $providers[] = new FakeSocialAuthProvider($additionalIdentity);
        }

        $authRepo = new SocialAuthRepositoryFake();
        $userDirectory = new SocialUserDirectoryFake($userResult);
        $identityRepo = new SocialIdentityRepositoryFake();
        if ($active) {
            $identityRepo->seed($this->identity($provider, $identity->openId, 'identity-1', $userResult['user_id'], SocialIdentity::STATUS_ACTIVE));
        }
        $service = new AuthApplicationService(
            authRepo: $authRepo,
            jwtCodec: new JwtTokenCodec('test-secret-at-least-32-chars-long-12345'),
            wsTicketIssuer: new WsTicketIssuer('test-ws-secret-at-least-32-chars-12345', 60),
            credentialVerifier: new LoginCredentialVerifier('local', 'dev-code', null, true),
            userDirectory: $userDirectory,
            socialProviderRegistry: new SocialAuthProviderRegistry($providers),
            socialIdentityRepository: $identityRepo,
        );

        return compact('service', 'authRepo', 'userDirectory', 'identityRepo');
    }

    private function externalIdentity(SocialProvider $provider): ExternalIdentity
    {
        return new ExternalIdentity(
            provider: $provider,
            providerAppId: $provider === SocialProvider::WECHAT ? 'wechat-app' : 'qq-app',
            identityNamespace: $provider === SocialProvider::WECHAT ? 'wechat-main' : 'qq-main',
            openId: $provider === SocialProvider::WECHAT ? 'wechat-openid' : 'qq-openid',
            unionId: $provider === SocialProvider::WECHAT ? 'wechat-unionid' : null,
        );
    }

    private function identity(
        SocialProvider $provider,
        string $openId,
        string $id,
        ?string $userId = null,
        int $status = SocialIdentity::STATUS_PENDING_PHONE,
    ): SocialIdentity {
        $unionId = $provider === SocialProvider::WECHAT ? $openId . '-unionid' : null;

        return new SocialIdentity(
            id: $id,
            provider: $provider,
            providerAppId: $provider === SocialProvider::WECHAT ? 'wechat-app' : 'qq-app',
            identityNamespace: $provider === SocialProvider::WECHAT ? 'wechat-main' : 'qq-main',
            subjectType: $provider === SocialProvider::WECHAT ? 'unionid' : 'openid',
            subjectId: $unionId ?? $openId,
            openId: $openId,
            unionId: $unionId,
            userId: $userId,
            status: $status,
            boundAt: $status === SocialIdentity::STATUS_ACTIVE ? '2026-01-01 00:00:00.000' : null,
            lastLoginAt: null,
            createdAt: '2026-01-01 00:00:00.000',
            updatedAt: '2026-01-01 00:00:00.000',
        );
    }

    /** @return array{device_uuid: string, platform: string, app_version: string} */
    private function device(): array
    {
        return [
            'device_uuid' => 'device-1',
            'platform'    => 'ios',
            'app_version' => '1.0.0',
        ];
    }
}
