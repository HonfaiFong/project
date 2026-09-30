<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Phone;

use Contracts\Errors\ErrorCode;
use Redis;
use RuntimeException;
use Services\AuthService\Domain\Phone\PhoneVerificationServiceInterface;
use Services\AuthService\Domain\Phone\SmsSenderInterface;
use Services\AuthService\Domain\Phone\VerificationScene;

final class RedisPhoneVerificationService implements PhoneVerificationServiceInterface
{
    public function __construct(
        private readonly Redis $redis,
        private readonly SmsSenderInterface $smsSender,
        private readonly string $codePepper,
        private readonly int $codeTtl = 300,
        private readonly int $resendInterval = 60,
        private readonly int $maxAttempts = 5,
        private readonly ?string $developmentCode = null,
    ) {
    }

    public function send(
        string $phone,
        VerificationScene $scene,
        string $contextId,
        array $context = []
    ): void {
        $phoneHash = hash('sha256', $phone);
        $verificationKey = $this->verificationKey($scene, $contextId, $phoneHash);
        $cooldownKey = $verificationKey . ':cooldown';

        if ((int)$this->redis->ttl($cooldownKey) > 0) {
            throw new RuntimeException(
                ErrorCode::RATE_LIMIT_EXCEEDED->message(),
                ErrorCode::RATE_LIMIT_EXCEEDED->value
            );
        }

        $this->checkRateLimit('phone', $phoneHash, 5, 3600);
        $this->checkRateLimit('phone-day', $phoneHash, 10, 86400);

        $ip = trim((string)($context['ip'] ?? ''));
        if ($ip !== '') {
            $this->checkRateLimit('ip', hash('sha256', $ip), 30, 3600);
        }

        $deviceUuid = trim((string)($context['device_uuid'] ?? ''));
        if ($deviceUuid !== '') {
            $this->checkRateLimit('device', hash('sha256', $deviceUuid), 10, 3600);
        }

        $code = $this->developmentCode !== null && $this->developmentCode !== ''
            ? $this->developmentCode
            : (string)random_int(100000, 999999);

        $this->redis->hMSet($verificationKey, [
            'code_hash' => $this->hashCode($code),
            'attempts'  => '0',
            'created_at' => (string)time(),
        ]);
        $this->redis->expire($verificationKey, $this->codeTtl);
        $this->redis->set($cooldownKey, '1', $this->resendInterval);

        try {
            $this->smsSender->sendVerificationCode($phone, $code, $scene);
        } catch (\Throwable $e) {
            $this->redis->del($verificationKey);
            throw $e;
        }
    }

    public function verify(
        string $phone,
        string $code,
        VerificationScene $scene,
        string $contextId
    ): void {
        $phoneHash = hash('sha256', $phone);
        $verificationKey = $this->verificationKey($scene, $contextId, $phoneHash);
        $data = $this->redis->hGetAll($verificationKey);
        if (!is_array($data) || $data === []) {
            throw new RuntimeException(
                ErrorCode::AUTH_SMS_CODE_EXPIRED->message(),
                ErrorCode::AUTH_SMS_CODE_EXPIRED->value
            );
        }

        $attempts = (int)($data['attempts'] ?? 0);
        if ($attempts >= $this->maxAttempts) {
            $this->redis->del($verificationKey);
            throw new RuntimeException(
                ErrorCode::RATE_LIMIT_EXCEEDED->message(),
                ErrorCode::RATE_LIMIT_EXCEEDED->value
            );
        }

        $expected = (string)($data['code_hash'] ?? '');
        if ($expected !== '' && hash_equals($expected, $this->hashCode(trim($code)))) {
            $this->redis->del($verificationKey);
            return;
        }

        $attempts = (int)$this->redis->hIncrBy($verificationKey, 'attempts', 1);
        if ($attempts >= $this->maxAttempts) {
            $this->redis->del($verificationKey);
            throw new RuntimeException(
                ErrorCode::RATE_LIMIT_EXCEEDED->message(),
                ErrorCode::RATE_LIMIT_EXCEEDED->value
            );
        }

        throw new RuntimeException(
            ErrorCode::AUTH_SMS_CODE_INVALID->message(),
            ErrorCode::AUTH_SMS_CODE_INVALID->value
        );
    }

    private function verificationKey(VerificationScene $scene, string $contextId, string $phoneHash): string
    {
        return 'auth:sms:' . $scene->value . ':' . $contextId . ':' . $phoneHash;
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, $this->codePepper);
    }

    private function checkRateLimit(string $scope, string $value, int $limit, int $ttl): void
    {
        $key = 'auth:sms:rate:' . $scope . ':' . $value;
        $count = (int)$this->redis->incr($key);
        if ($count === 1) {
            $this->redis->expire($key, $ttl);
        }
        if ($count > $limit) {
            throw new RuntimeException(
                ErrorCode::RATE_LIMIT_EXCEEDED->message(),
                ErrorCode::RATE_LIMIT_EXCEEDED->value
            );
        }
    }
}
