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

        $rateKeys = [
            'auth:sms:rate:phone:' . $phoneHash,
            'auth:sms:rate:phone-day:' . $phoneHash,
        ];
        $limits = [5, 10];
        $ttls = [3600, 86400];
        $ip = trim((string)($context['ip'] ?? ''));
        if ($ip !== '') {
            $rateKeys[] = 'auth:sms:rate:ip:' . hash('sha256', $ip);
            $limits[] = 30;
            $ttls[] = 3600;
        }
        $deviceUuid = trim((string)($context['device_uuid'] ?? ''));
        if ($deviceUuid !== '') {
            $rateKeys[] = 'auth:sms:rate:device:' . hash('sha256', $deviceUuid);
            $limits[] = 10;
            $ttls[] = 3600;
        }
        $owner = bin2hex(random_bytes(16));
        $reservation = $this->redis->eval(<<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 1 then return {} end
for i = 2, #KEYS do
    -- Upgrade existing numeric rate keys without resetting their quota/window.
    if redis.call('TYPE', KEYS[i]).ok == 'string' then
        local count = tonumber(redis.call('GET', KEYS[i]) or '0')
        local ttl = redis.call('TTL', KEYS[i])
        redis.call('DEL', KEYS[i])
        redis.call('HSET', KEYS[i], 'window', ARGV[1], 'count', count)
        redis.call('EXPIRE', KEYS[i], ttl > 0 and ttl or ARGV[2 * i])
    end
    if tonumber(redis.call('HGET', KEYS[i], 'count') or '0') >= tonumber(ARGV[2 * i - 1]) then
        return {}
    end
end
if not redis.call('SET', KEYS[1], ARGV[1], 'NX', 'EX', ARGV[2]) then return {} end
local windows = {}
for i = 2, #KEYS do
    local window = redis.call('HGET', KEYS[i], 'window')
    if not window then
        window = ARGV[1]
        redis.call('HSET', KEYS[i], 'window', window, 'count', 0)
    end
    redis.call('HINCRBY', KEYS[i], 'count', 1)
    if redis.call('TTL', KEYS[i]) < 0 then redis.call('EXPIRE', KEYS[i], ARGV[2 * i]) end
    windows[#windows + 1] = window
end
return windows
LUA, array_merge([$cooldownKey], $rateKeys, [$owner, $this->resendInterval],
            $this->rateArguments($limits, $ttls)), count($rateKeys) + 1);
        if (!is_array($reservation) || $reservation === []) {
            throw new RuntimeException(ErrorCode::RATE_LIMIT_EXCEEDED->message(), ErrorCode::RATE_LIMIT_EXCEEDED->value);
        }

        $code = $this->developmentCode !== null && $this->developmentCode !== ''
            ? $this->developmentCode
            : (string)random_int(100000, 999999);

        try {
            $stored = $this->redis->eval(<<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[1] then return 0 end
redis.call('HSET', KEYS[2], 'code_hash', ARGV[2], 'attempts', '0', 'created_at', ARGV[3])
redis.call('EXPIRE', KEYS[2], ARGV[4])
return 1
LUA, [$cooldownKey, $verificationKey, $owner, $this->hashCode($code), time(), $this->codeTtl], 2);
            if ($stored !== 1) {
                throw new RuntimeException(ErrorCode::RATE_LIMIT_EXCEEDED->message(), ErrorCode::RATE_LIMIT_EXCEEDED->value);
            }

            $this->smsSender->sendVerificationCode($phone, $code, $scene);
        } catch (\Throwable $e) {
            // Refund only this reservation; a later cooldown owner/window is untouched.
            $this->redis->eval(<<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    redis.call('DEL', KEYS[1], KEYS[2])
end
for i = 3, #KEYS do
    if redis.call('HGET', KEYS[i], 'window') == ARGV[i - 1] then
        local count = tonumber(redis.call('HGET', KEYS[i], 'count') or '0')
        if count > 0 then redis.call('HINCRBY', KEYS[i], 'count', -1) end
    end
end
return 1
LUA, array_merge([$cooldownKey, $verificationKey], $rateKeys, [$owner], $reservation), count($rateKeys) + 2);
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
        $result = (int)$this->redis->eval(<<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then return 1 end
local attempts = tonumber(redis.call('HGET', KEYS[1], 'attempts') or '0')
if attempts >= tonumber(ARGV[2]) then redis.call('DEL', KEYS[1]); return 2 end
if redis.call('HGET', KEYS[1], 'code_hash') == ARGV[1] then redis.call('DEL', KEYS[1]); return 0 end
attempts = redis.call('HINCRBY', KEYS[1], 'attempts', 1)
if attempts >= tonumber(ARGV[2]) then redis.call('DEL', KEYS[1]); return 2 end
return 3
LUA, [$verificationKey, $this->hashCode(trim($code)), $this->maxAttempts], 1);
        if ($result === 0) {
            return;
        }
        $error = match ($result) {
            1 => ErrorCode::AUTH_SMS_CODE_EXPIRED,
            2 => ErrorCode::RATE_LIMIT_EXCEEDED,
            default => ErrorCode::AUTH_SMS_CODE_INVALID,
        };
        throw new RuntimeException($error->message(), $error->value);
    }

    private function verificationKey(VerificationScene $scene, string $contextId, string $phoneHash): string
    {
        return 'auth:sms:' . $scene->value . ':' . $contextId . ':' . $phoneHash;
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, $this->codePepper);
    }

    /** @param array<int> $limits @param array<int> $ttls @return array<int> */
    private function rateArguments(array $limits, array $ttls): array
    {
        $arguments = [];
        foreach ($limits as $index => $limit) {
            $arguments[] = $limit;
            $arguments[] = $ttls[$index];
        }
        return $arguments;
    }
}
