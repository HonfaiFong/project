<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Errors\ErrorCode;
use Redis;
use Services\AuthService\Domain\Phone\SmsSenderInterface;
use Services\AuthService\Domain\Phone\VerificationScene;
use Services\AuthService\Infrastructure\Phone\RedisPhoneVerificationService;
use Tests\TestCase;

final class PhoneVerificationRedisFailureTest extends TestCase
{
    public function testRedisCommandFailureDoesNotCountAsSuccessfulVerification(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('eval')->willReturn(false);
        $sender = $this->createMock(SmsSenderInterface::class);
        $sender->expects($this->never())->method('sendVerificationCode');
        $service = new RedisPhoneVerificationService($redis, $sender, 'test-pepper');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(ErrorCode::SERVICE_UNAVAILABLE->value);
        $service->verify('+8613800000000', '123456', VerificationScene::SOCIAL_BIND_PHONE, 'identity-id', 'bind-token');
    }
}
