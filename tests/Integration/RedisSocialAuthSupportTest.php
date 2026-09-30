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

    public function sendVerificationCode(string $phone, string $code, VerificationScene $scene): void
    {
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

    private function trackBindToken(string $identityId, string $token): void
    {
        $tokenHash = hash('sha256', $token);
        $this->keysToDelete[] = 'auth:social:bind:token:' . $tokenHash;
        $this->keysToDelete[] = 'auth:social:bind:identity:' . $identityId;
    }
}
