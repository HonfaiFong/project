<?php

declare(strict_types=1);

namespace Tests\Integration;

use Contracts\Errors\ErrorCode;
use Redis;
use Services\AuthService\Domain\Phone\VerificationScene;
use Services\AuthService\Infrastructure\Cache\RedisDistributedLock;
use Services\AuthService\Infrastructure\Cache\RedisSocialBindSessionStore;
use Services\AuthService\Infrastructure\Phone\RedisPhoneVerificationService;
use Services\AuthService\Domain\Phone\SmsSenderInterface;
use Tests\TestCase;

final class RedisSmsSenderFake implements SmsSenderInterface
{
    /** @var array<array{phone: string, code: string, scene: VerificationScene}> */
    public array $messages = [];

    public bool $fail = false;

    public ?\Closure $duringSend = null;

    public function sendVerificationCode(string $phone, string $code, VerificationScene $scene): void
    {
        if ($this->duringSend !== null) {
            ($this->duringSend)();
        }
        if ($this->fail) {
            throw new \RuntimeException('SMS provider unavailable');
        }
        $this->messages[] = [
            'phone' => $phone,
            'code'  => $code,
            'scene' => $scene,
        ];
    }
}

final class RedisSocialAuthSupportTest extends TestCase
{
    private Redis $redis;

    /** @var array<string> */
    private array $keysToDelete = [];

    protected function setUp(): void
    {
        $this->redis = new Redis();
        try {
            $connected = $this->redis->connect(
                getenv('SOCIAL_TEST_REDIS_HOST') ?: '127.0.0.1',
                (int)(getenv('SOCIAL_TEST_REDIS_PORT') ?: 6379),
                0.2,
            );
            if (!$connected || !$this->redis->ping()) {
                $this->markTestSkipped('Redis integration service is not available');
            }
        } catch (\Throwable) {
            $this->markTestSkipped('Redis integration service is not available');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            foreach ($this->keysToDelete as $key) {
                $this->redis->del($key);
            }
            $this->redis->close();
        }
    }

    public function testBindSessionRotationPhoneLockAndConsumption(): void
    {
        $store = new RedisSocialBindSessionStore($this->redis);
        $identityId = 'integration-' . bin2hex(random_bytes(8));

        $first = $store->issue($identityId, 60);
        $second = $store->issue($identityId, 60);
        $this->trackBindToken($identityId, $first['bind_token']);
        $this->trackBindToken($identityId, $second['bind_token']);

        $this->assertNull($store->verify($first['bind_token']));
        $session = $store->setPhone($second['bind_token'], '+8613800000000');
        $this->assertSame(hash('sha256', '+8613800000000'), $session->phoneHash);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(ErrorCode::AUTH_SOCIAL_BIND_PHONE_CHANGED->value);
        $store->assertPhone($second['bind_token'], '+8613900000000');
    }

    public function testPhoneVerificationStoresOnlyHashAndEnforcesCooldownAndAttempts(): void
    {
        $sender = new RedisSmsSenderFake();
        $service = new RedisPhoneVerificationService(
            redis: $this->redis,
            smsSender: $sender,
            codePepper: 'integration-test-pepper',
            codeTtl: 60,
            resendInterval: 60,
            maxAttempts: 2,
            developmentCode: '123456',
        );
        $phone = '+86139' . random_int(10000000, 99999999);
        $contextId = 'integration-' . bin2hex(random_bytes(8));
        $phoneHash = hash('sha256', $phone);
        $verificationKey = 'auth:sms:social_bind_phone:' . $contextId . ':' . $phoneHash;
        $cooldownKey = $verificationKey . ':cooldown';
        $phoneRateKey = 'auth:sms:rate:phone:' . $phoneHash;
        $phoneDayRateKey = 'auth:sms:rate:phone-day:' . $phoneHash;
        $this->keysToDelete = [$verificationKey, $cooldownKey, $phoneRateKey, $phoneDayRateKey];

        $service->send($phone, VerificationScene::SOCIAL_BIND_PHONE, $contextId);
        $this->assertSame('123456', $sender->messages[0]['code']);
        $this->assertNotSame('123456', (string)$this->redis->hGet($verificationKey, 'code_hash'));

        try {
            $service->verify($phone, '000000', VerificationScene::SOCIAL_BIND_PHONE, $contextId);
            $this->fail('Expected invalid verification code');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SMS_CODE_INVALID->value, $e->getCode());
        }

        try {
            $service->send($phone, VerificationScene::SOCIAL_BIND_PHONE, $contextId);
            $this->fail('Expected resend cooldown');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::RATE_LIMIT_EXCEEDED->value, $e->getCode());
        }

        try {
            $service->verify($phone, '000000', VerificationScene::SOCIAL_BIND_PHONE, $contextId);
            $this->fail('Expected attempt limit');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::RATE_LIMIT_EXCEEDED->value, $e->getCode());
        }
    }

    public function testDistributedLockAllowsOnlyOneOwner(): void
    {
        $lock = new RedisDistributedLock($this->redis);
        $key = 'integration:lock:' . bin2hex(random_bytes(8));
        $this->keysToDelete[] = $key;

        $owner = $lock->acquire($key, 60);
        $this->assertNotNull($owner);
        $this->assertNull($lock->acquire($key, 60));

        $lock->release($key, (string)$owner);
        $this->assertNotNull($lock->acquire($key, 60));
    }

    public function testVerifiedSessionExtendsBothKeysAndExpiry(): void
    {
        $store = new RedisSocialBindSessionStore($this->redis);
        $identityId = 'integration-' . bin2hex(random_bytes(8));
        $issued = $store->issue($identityId, 5);
        $token = $issued['bind_token'];
        $this->trackBindToken($identityId, $token);
        $store->setPhone($token, '+8613800000000');
        $session = $store->markPhoneVerified($token, '+8613800000000');
        $this->assertTrue($session->phoneVerified);
        $this->assertGreaterThanOrEqual(time() + 299, $session->expiresAt);
        foreach ($this->keysToDelete as $key) {
            $this->assertGreaterThanOrEqual(299, $this->redis->ttl($key));
        }
        $this->assertSame($session->expiresAt, (int)$this->redis->hGet($this->keysToDelete[0], 'expires_at'));
    }

    public function testConcurrentSendIsRejectedAndProviderFailureRefundsReservation(): void
    {
        $sender = new RedisSmsSenderFake();
        $service = new RedisPhoneVerificationService($this->redis, $sender, 'integration-test-pepper', developmentCode: '123456');
        $phone = '+86139' . random_int(10000000, 99999999);
        $contextId = 'integration-' . bin2hex(random_bytes(8));
        $hash = hash('sha256', $phone);
        $key = 'auth:sms:social_bind_phone:' . $contextId . ':' . $hash;
        $this->keysToDelete = [$key, $key . ':cooldown', 'auth:sms:rate:phone:' . $hash, 'auth:sms:rate:phone-day:' . $hash];
        $sender->duringSend = function () use ($service, $phone, $contextId): void {
            try {
                $service->send($phone, VerificationScene::SOCIAL_BIND_PHONE, $contextId);
                $this->fail('Concurrent send must be rejected before provider invocation');
            } catch (\RuntimeException $e) {
                $this->assertSame(ErrorCode::RATE_LIMIT_EXCEEDED->value, $e->getCode());
            }
        };
        $sender->fail = true;
        try {
            $service->send($phone, VerificationScene::SOCIAL_BIND_PHONE, $contextId);
            $this->fail('Expected provider failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('SMS provider unavailable', $e->getMessage());
        }
        $this->assertSame(0, $this->redis->exists($key . ':cooldown'));
        $this->assertSame(0, $this->redis->exists($key));
        foreach (array_slice($this->keysToDelete, 2) as $rateKey) {
            $this->assertSame('0', $this->redis->hGet($rateKey, 'count'));
            $this->assertGreaterThan(0, $this->redis->ttl($rateKey));
        }
        $sender->fail = false;
        $service->send($phone, VerificationScene::SOCIAL_BIND_PHONE, $contextId);
        $this->assertCount(1, $sender->messages);
        $service->verify($phone, '123456', VerificationScene::SOCIAL_BIND_PHONE, $contextId);
        $this->expectExceptionCode(ErrorCode::AUTH_SMS_CODE_EXPIRED->value);
        $service->verify($phone, '123456', VerificationScene::SOCIAL_BIND_PHONE, $contextId);
    }

    public function testExistingNumericQuotaIsPreservedDuringUpgrade(): void
    {
        $sender = new RedisSmsSenderFake();
        $service = new RedisPhoneVerificationService($this->redis, $sender, 'integration-test-pepper');
        $phone = '+86139' . random_int(10000000, 99999999);
        $contextId = 'integration-' . bin2hex(random_bytes(8));
        $hash = hash('sha256', $phone);
        $key = 'auth:sms:rate:phone:' . $hash;
        $this->keysToDelete = [$key];
        $this->redis->set($key, '5', 60);
        try {
            $service->send($phone, VerificationScene::SOCIAL_BIND_PHONE, $contextId);
            $this->fail('Existing quota must not be reset');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::RATE_LIMIT_EXCEEDED->value, $e->getCode());
        }
        $this->assertSame('5', $this->redis->hGet($key, 'count'));
        $this->assertGreaterThan(0, $this->redis->ttl($key));
        $this->assertLessThanOrEqual(60, $this->redis->ttl($key));
        $this->assertCount(0, $sender->messages);
    }

    public function testSmsConsumptionPersistsVerifiedSessionBeforeAnyLaterWriteAndSurvivesLostReply(): void
    {
        $store = new RedisSocialBindSessionStore($this->redis);
        $sender = new RedisSmsSenderFake();
        $service = new RedisPhoneVerificationService($this->redis, $sender, 'integration-test-pepper', developmentCode: '123456');
        $identityId = 'integration-' . bin2hex(random_bytes(8));
        $phone = '+86139' . random_int(10000000, 99999999);
        $issued = $store->issue($identityId, 5);
        $token = $issued['bind_token'];
        $this->trackBindToken($identityId, $token);
        $store->setPhone($token, $phone);
        $hash = hash('sha256', $phone);
        $key = 'auth:sms:social_bind_phone:' . $identityId . ':' . $hash;
        array_push($this->keysToDelete, $key, $key . ':cooldown', 'auth:sms:rate:phone:' . $hash, 'auth:sms:rate:phone-day:' . $hash);
        $service->send($phone, VerificationScene::SOCIAL_BIND_PHONE, $identityId);

        // 验证后立即停止，模拟工作进程丢失回复或在 markPhoneVerified / UserService 执行前崩溃。
        $service->verify($phone, '123456', VerificationScene::SOCIAL_BIND_PHONE, $identityId, $token);
        $this->assertSame(0, $this->redis->exists($key));
        $recovered = (new RedisSocialBindSessionStore($this->redis))->verify($token);
        $this->assertNotNull($recovered);
        $this->assertTrue($recovered->phoneVerified);
        $this->assertSame($phone, $recovered->verifiedPhone);
        $this->assertGreaterThanOrEqual(time() + 299, $recovered->expiresAt);
        // 重复相同状态转换保持幂等，无须短信键。
        $service->verify($phone, 'unused', VerificationScene::SOCIAL_BIND_PHONE, $identityId, $token);
        $this->assertTrue($store->verify($token)->phoneVerified);
    }

    public function testRotatedBindTokenCannotConsumeSmsOrVerifyANewSession(): void
    {
        $store = new RedisSocialBindSessionStore($this->redis);
        $service = new RedisPhoneVerificationService($this->redis, new RedisSmsSenderFake(), 'integration-test-pepper', developmentCode: '123456');
        $identityId = 'integration-' . bin2hex(random_bytes(8));
        $phone = '+86139' . random_int(10000000, 99999999);
        $first = $store->issue($identityId, 60)['bind_token'];
        $store->setPhone($first, $phone);
        $second = $store->issue($identityId, 60)['bind_token'];
        $store->setPhone($second, $phone);
        $this->trackBindToken($identityId, $first);
        $this->trackBindToken($identityId, $second);
        $hash = hash('sha256', $phone);
        $key = 'auth:sms:social_bind_phone:' . $identityId . ':' . $hash;
        array_push($this->keysToDelete, $key, $key . ':cooldown', 'auth:sms:rate:phone:' . $hash, 'auth:sms:rate:phone-day:' . $hash);
        $service->send($phone, VerificationScene::SOCIAL_BIND_PHONE, $identityId);
        try {
            $service->verify($phone, '123456', VerificationScene::SOCIAL_BIND_PHONE, $identityId, $first);
            $this->fail('Rotated token must not verify');
        } catch (\RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_SOCIAL_BIND_TOKEN_INVALID->value, $e->getCode());
        }
        $this->assertSame(1, $this->redis->exists($key));
        $this->assertFalse($store->verify($second)->phoneVerified);
        $service->verify($phone, '123456', VerificationScene::SOCIAL_BIND_PHONE, $identityId, $second);
        $this->assertTrue($store->verify($second)->phoneVerified);
    }

    private function trackBindToken(string $identityId, string $token): void
    {
        $tokenHash = hash('sha256', $token);
        $this->keysToDelete[] = 'auth:social:bind:token:' . $tokenHash;
        $this->keysToDelete[] = 'auth:social:bind:identity:' . $identityId;
    }
}
