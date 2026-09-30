<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Errors\ErrorCode;
use Packages\Phone\PhoneNormalizer;
use Services\AuthService\Application\Service\AuthApplicationService;
use Services\AuthService\Domain\Auth\ExternalIdentity;
use Services\AuthService\Domain\Auth\SocialAuthProviderInterface;
use Services\AuthService\Domain\Auth\SocialProvider;
use Services\AuthService\Domain\Client\UserDirectoryInterface;
use Services\AuthService\Domain\Entity\Device;
use Services\AuthService\Domain\Entity\RefreshToken;
use Services\AuthService\Domain\Entity\SocialBindSession;
use Services\AuthService\Domain\Entity\SocialIdentity;
use Services\AuthService\Domain\Phone\PhoneVerificationServiceInterface;
use Services\AuthService\Domain\Phone\VerificationScene;
use Services\AuthService\Domain\Repository\AuthRepositoryInterface;
use Services\AuthService\Domain\Repository\DistributedLockInterface;
use Services\AuthService\Domain\Repository\SocialBindSessionStoreInterface;
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
        $device = new Device(
            id: 'device-' . (count($this->devices) + 1),
            userId: $userId,
            deviceUuid: $deviceUuid,
            platform: $platform,
            appVersion: $appVersion,
            pushToken: $pushToken,
            lastIp: $ip,
        );
        $this->devices[$deviceUuid] = $device;

        return $device;
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
        if (!isset($this->tokens[$tokenHash])) {
            return false;
        }

        unset($this->tokens[$tokenHash]);

        return true;
    }
}

final class SocialUserDirectoryFake implements UserDirectoryInterface
{
    /** @var array<array{phone: string, register_channel: string}> */
    public array $calls = [];

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

    public function bindToUser(string $identityId, string $userId): SocialIdentity
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

final class SocialBindSessionStoreFake implements SocialBindSessionStoreInterface
{
    /** @var array<string, SocialBindSession> */
    private array $sessions = [];

    /** @var array<string, string> */
    private array $currentByIdentity = [];

    private int $sequence = 0;

    public function issue(string $socialIdentityId, int $ttlSeconds): array
    {
        $this->sequence++;
        $token = 'bind-token-' . $this->sequence;
        $tokenHash = hash('sha256', $token);
        $now = time();
        $session = new SocialBindSession(
            tokenHash: $tokenHash,
            socialIdentityId: $socialIdentityId,
            expiresAt: $now + $ttlSeconds,
            issuedAt: $now,
        );
        $this->sessions[$tokenHash] = $session;
        $this->currentByIdentity[$socialIdentityId] = $tokenHash;

        return [
            'bind_token'            => $token,
            'bind_token_expires_at' => $session->expiresAt,
        ];
    }

    public function verify(string $bindToken): ?SocialBindSession
    {
        $tokenHash = hash('sha256', trim($bindToken));
        $session = $this->sessions[$tokenHash] ?? null;
        if ($session === null || ($this->currentByIdentity[$session->socialIdentityId] ?? null) !== $tokenHash) {
            return null;
        }
        if ($session->expiresAt < time()) {
            return null;
        }

        return $session;
    }

    public function setPhone(string $bindToken, string $phone): SocialBindSession
    {
        $session = $this->requireSession($bindToken);
        $phoneHash = hash('sha256', $phone);
        if ($session->phoneHash !== null && !hash_equals($session->phoneHash, $phoneHash)) {
            throw new \RuntimeException(
                ErrorCode::AUTH_SOCIAL_BIND_PHONE_CHANGED->message(),
                ErrorCode::AUTH_SOCIAL_BIND_PHONE_CHANGED->value
            );
        }

        return $this->save($session, phoneHash: $phoneHash);
    }

    public function assertPhone(string $bindToken, string $phone): SocialBindSession
    {
        $session = $this->requireSession($bindToken);
        if ($session->phoneHash === null || !hash_equals($session->phoneHash, hash('sha256', $phone))) {
            throw new \RuntimeException(
                ErrorCode::AUTH_SOCIAL_BIND_PHONE_CHANGED->message(),
                ErrorCode::AUTH_SOCIAL_BIND_PHONE_CHANGED->value
            );
        }

        return $session;
    }

    public function markPhoneVerified(string $bindToken, string $phone): SocialBindSession
    {
        $session = $this->setPhone($bindToken, $phone);

        return $this->save($session, phoneVerified: true, verifiedPhone: $phone);
    }

    public function consume(string $bindToken): void
    {
        $session = $this->verify($bindToken);
        if ($session === null) {
            return;
        }

        unset($this->sessions[$session->tokenHash]);
        if (($this->currentByIdentity[$session->socialIdentityId] ?? null) === $session->tokenHash) {
            unset($this->currentByIdentity[$session->socialIdentityId]);
        }
    }

    private function requireSession(string $bindToken): SocialBindSession
    {
        $session = $this->verify($bindToken);
        if ($session === null) {
            throw new \RuntimeException(
                ErrorCode::AUTH_SOCIAL_BIND_TOKEN_INVALID->message(),
                ErrorCode::AUTH_SOCIAL_BIND_TOKEN_INVALID->value
            );
        }

        return $session;
    }

    private function save(
        SocialBindSession $session,
        ?string $phoneHash = null,
        ?bool $phoneVerified = null,
        ?string $verifiedPhone = null
    ): SocialBindSession {
        $updated = new SocialBindSession(
            tokenHash: $session->tokenHash,
            socialIdentityId: $session->socialIdentityId,
            expiresAt: $session->expiresAt,
            phoneHash: $phoneHash ?? $session->phoneHash,
            phoneVerified: $phoneVerified ?? $session->phoneVerified,
            verifiedPhone: $verifiedPhone ?? $session->verifiedPhone,
            issuedAt: $session->issuedAt,
        );
        $this->sessions[$session->tokenHash] = $updated;

        return $updated;
    }
}

final class SocialPhoneVerificationFake implements PhoneVerificationServiceInterface
{
    /** @var array<array{phone: string, scene: VerificationScene, context_id: string, context: array}> */
    public array $sent = [];

    public int $verifyCalls = 0;

    public function send(string $phone, VerificationScene $scene, string $contextId, array $context = []): void
    {
        $this->sent[] = [
            'phone'       => $phone,
            'scene'       => $scene,
            'context_id'  => $contextId,
            'context'     => $context,
        ];
    }

    public function verify(string $phone, string $code, VerificationScene $scene, string $contextId): void
    {
        $this->verifyCalls++;
        if ($code !== '123456') {
            throw new \RuntimeException(
                ErrorCode::AUTH_SMS_CODE_INVALID->message(),
                ErrorCode::AUTH_SMS_CODE_INVALID->value
            );
        }
    }
}

final class SocialLockFake implements DistributedLockInterface
{
    public int $acquires = 0;

    public int $releases = 0;

    public function acquire(string $key, int $ttlSeconds): string
    {
        $this->acquires++;

        return 'lock-owner';
    }

    public function release(string $key, string $owner): void
    {
        $this->releases++;
    }
}

final class FakeSocialAuthProvider implements SocialAuthProviderInterface
{
    public function __construct(private readonly ExternalIdentity $identity)
    {
    }

    public function provider(): SocialProvider
    {
        return $this->identity->provider;
    }

    public function authenticate(string $code): ExternalIdentity
    {
        return $this->identity;
    }
}

final class SocialAuthServiceTest extends TestCase
{
    public function testFirstSocialLoginOnlyReturnsAOneTimeBindToken(): void
    {
        $fixture = $this->fixture(SocialProvider::WECHAT);

        $result = $fixture['service']->socialLogin(
            SocialProvider::WECHAT,
            'wechat-code',
            $this->device(),
        );

        $this->assertSame('phone_binding_required', $result['state']);
        $this->assertArrayHasKey('bind_token', $result);
        $this->assertArrayNotHasKey('login', $result);
        $this->assertCount(0, $fixture['authRepo']->tokens);
        $this->assertNull($fixture['identityRepo']->findById('identity-1')?->userId);
    }

    public function testPendingLoginRotatesThePreviousBindToken(): void
    {
        $fixture = $this->fixture(SocialProvider::WECHAT);
        $first = $fixture['service']->socialLogin(SocialProvider::WECHAT, 'code', $this->device());
        $second = $fixture['service']->socialLogin(SocialProvider::WECHAT, 'code', $this->device());

        $this->assertNotSame($first['bind_token'], $second['bind_token']);
        $this->assertNull($fixture['bindStore']->verify($first['bind_token']));
        $this->assertNotNull($fixture['bindStore']->verify($second['bind_token']));
    }

    public function testActiveSocialIdentityIssuesTheNormalSessionDirectly(): void
    {
        $fixture = $this->fixture(SocialProvider::QQ, ['user_id' => '10001', 'is_new_user' => false], true);

        $result = $fixture['service']->socialLogin(SocialProvider::QQ, 'code', $this->device());

        $this->assertSame('authenticated', $result['state']);
        $this->assertArrayHasKey('login', $result);
        $this->assertArrayNotHasKey('bind_token', $result);
        $this->assertSame('10001', $result['login']['user_id']);
        $this->assertCount(1, $fixture['authRepo']->tokens);
        $this->assertSame(0, $fixture['phone']->verifyCalls);
    }

    public function testVerifiedExistingPhoneBindsWeChatWithoutCreatingAnotherUser(): void
    {
        $fixture = $this->fixture(SocialProvider::WECHAT, ['user_id' => '10001', 'is_new_user' => false]);
        $login = $fixture['service']->socialLogin(SocialProvider::WECHAT, 'code', $this->device());

        $fixture['service']->sendSocialPhoneCode(
            $login['bind_token'],
            '13800000000',
            ['ip' => '203.0.113.10', 'device_uuid' => 'device-1'],
        );
        $result = $fixture['service']->bindSocialPhone(
            $login['bind_token'],
            '13800000000',
            '123456',
            $this->device(),
        );

        $this->assertSame('10001', $result['login']['user_id']);
        $this->assertFalse($result['login']['is_new_user']);
        $this->assertSame(
            [['phone' => '+8613800000000', 'register_channel' => 'wechat']],
            $fixture['userDirectory']->calls,
        );
        $boundIdentity = $fixture['identityRepo']->findById('identity-1');
        $this->assertNotNull($boundIdentity);
        $this->assertSame(SocialIdentity::STATUS_ACTIVE, $boundIdentity->status);
        $this->assertSame('10001', $boundIdentity->userId);
        $this->assertCount(1, $fixture['authRepo']->tokens);
        $this->assertSame(1, $fixture['phone']->verifyCalls);
    }

    public function testNewPhoneThroughQqCreatesUserWithQqRegisterChannel(): void
    {
        $fixture = $this->fixture(SocialProvider::QQ, ['user_id' => '10002', 'is_new_user' => true]);
        $login = $fixture['service']->socialLogin(SocialProvider::QQ, 'code', $this->device());
        $fixture['service']->sendSocialPhoneCode($login['bind_token'], '+8613900000000');

        $result = $fixture['service']->bindSocialPhone(
            $login['bind_token'],
            '+8613900000000',
            '123456',
            $this->device(),
        );

        $this->assertTrue($result['login']['is_new_user']);
        $this->assertSame(
            [['phone' => '+8613900000000', 'register_channel' => 'qq']],
            $fixture['userDirectory']->calls,
        );
    }

    public function testWeChatAndQqCanConvergeOnTheSameExistingUser(): void
    {
        $qqIdentity = new ExternalIdentity(
            provider: SocialProvider::QQ,
            providerAppId: 'qq-app',
            identityNamespace: 'qq-main',
            openId: 'qq-openid',
        );
        $fixture = $this->fixture(
            SocialProvider::WECHAT,
            ['user_id' => '10001', 'is_new_user' => false],
            additionalIdentities: [$qqIdentity],
        );

        $wechat = $fixture['service']->socialLogin(SocialProvider::WECHAT, 'wechat-code', $this->device());
        $fixture['service']->sendSocialPhoneCode($wechat['bind_token'], '13800000000');
        $fixture['service']->bindSocialPhone($wechat['bind_token'], '13800000000', '123456', $this->device());

        $qq = $fixture['service']->socialLogin(SocialProvider::QQ, 'qq-code', $this->device());
        $fixture['service']->sendSocialPhoneCode($qq['bind_token'], '13800000000');
        $result = $fixture['service']->bindSocialPhone($qq['bind_token'], '13800000000', '123456', $this->device());

        $this->assertSame('10001', $result['login']['user_id']);
        $this->assertCount(2, $fixture['identityRepo']->identities);
        $this->assertSame(
            ['wechat', 'qq'],
            array_column($fixture['userDirectory']->calls, 'register_channel'),
        );
        $this->assertSame('10001', $fixture['identityRepo']->findById('identity-1')?->userId);
        $this->assertSame('10001', $fixture['identityRepo']->findById('identity-2')?->userId);
    }

    public function testAlreadyBoundProviderCannotBeOverwritten(): void
    {
        $fixture = $this->fixture(SocialProvider::WECHAT, ['user_id' => '10001', 'is_new_user' => false]);
        $fixture['identityRepo']->seed($this->identity(
            SocialProvider::WECHAT,
            'existing-wechat',
            'existing-identity',
            '10001',
            SocialIdentity::STATUS_ACTIVE,
        ));

        $login = $fixture['service']->socialLogin(SocialProvider::WECHAT, 'new-code', $this->device());
        $fixture['service']->sendSocialPhoneCode($login['bind_token'], '13800000000');

        try {
            $fixture['service']->bindSocialPhone($login['bind_token'], '13800000000', '123456', $this->device());
            $this->fail('Expected provider conflict');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_PROVIDER_ALREADY_BOUND->value, $e->getCode());
        }

        $this->assertSame('10001', $fixture['identityRepo']->findById('existing-identity')?->userId);
    }

    public function testVerifiedPhoneCanResumeAfterUserServiceFailure(): void
    {
        $fixture = $this->fixture(SocialProvider::WECHAT);
        $login = $fixture['service']->socialLogin(SocialProvider::WECHAT, 'code', $this->device());
        $fixture['service']->sendSocialPhoneCode($login['bind_token'], '13800000000');
        $fixture['userDirectory']->failuresRemaining = 1;

        try {
            $fixture['service']->bindSocialPhone($login['bind_token'], '13800000000', '123456', $this->device());
            $this->fail('Expected the first UserService call to fail');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::SERVICE_UNAVAILABLE->value, $e->getCode());
        }

        $result = $fixture['service']->bindSocialPhone($login['bind_token'], '13800000000', '', $this->device());

        $this->assertSame('10001', $result['login']['user_id']);
        $this->assertSame(1, $fixture['phone']->verifyCalls);
        $this->assertCount(2, $fixture['userDirectory']->calls);
    }

    /**
     * @param array{user_id: string, is_new_user: bool} $userResult
     * @param array<ExternalIdentity> $additionalIdentities
     * @return array{
     *     service: AuthApplicationService,
     *     authRepo: SocialAuthRepositoryFake,
     *     userDirectory: SocialUserDirectoryFake,
     *     identityRepo: SocialIdentityRepositoryFake,
     *     bindStore: SocialBindSessionStoreFake,
     *     phone: SocialPhoneVerificationFake,
     *     lock: SocialLockFake
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
        $bindStore = new SocialBindSessionStoreFake();
        $phone = new SocialPhoneVerificationFake();
        $lock = new SocialLockFake();
        $service = new AuthApplicationService(
            authRepo: $authRepo,
            jwtCodec: new JwtTokenCodec('test-secret-at-least-32-chars-long-12345'),
            wsTicketIssuer: new WsTicketIssuer('test-ws-secret-at-least-32-chars-12345', 60),
            credentialVerifier: new LoginCredentialVerifier('local', 'dev-code'),
            userDirectory: $userDirectory,
            socialProviderRegistry: new SocialAuthProviderRegistry($providers),
            socialIdentityRepository: $identityRepo,
            socialBindSessionStore: $bindStore,
            phoneVerificationService: $phone,
            distributedLock: $lock,
            phoneNormalizer: new PhoneNormalizer(),
        );

        return compact('service', 'authRepo', 'userDirectory', 'identityRepo', 'bindStore', 'phone', 'lock');
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
