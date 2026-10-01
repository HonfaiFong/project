<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Cache;

use Contracts\Errors\ErrorCode;
use Redis;
use RuntimeException;
use Services\AuthService\Domain\Entity\SocialBindSession;
use Services\AuthService\Domain\Repository\SocialBindSessionStoreInterface;

final class RedisSocialBindSessionStore implements SocialBindSessionStoreInterface
{
    private const TOKEN_PREFIX = 'auth:social:bind:token:';
    private const IDENTITY_PREFIX = 'auth:social:bind:identity:';

    public function __construct(private readonly Redis $redis)
    {
    }

    public function issue(string $socialIdentityId, int $ttlSeconds): array
    {
        if ($ttlSeconds < 1) {
            throw new RuntimeException(
                ErrorCode::INTERNAL_SERVER_ERROR->message(),
                ErrorCode::INTERNAL_SERVER_ERROR->value
            );
        }

        $bindToken = $this->randomToken();
        $tokenHash = hash('sha256', $bindToken);
        $tokenKey = self::TOKEN_PREFIX . $tokenHash;
        $identityKey = self::IDENTITY_PREFIX . $socialIdentityId;
        $oldHash = $this->redis->get($identityKey);
        $issuedAt = time();
        $expiresAt = $issuedAt + $ttlSeconds;

        $this->redis->multi();
        if (is_string($oldHash) && $oldHash !== '') {
            $this->redis->del(self::TOKEN_PREFIX . $oldHash);
        }
        $this->redis->hMSet($tokenKey, [
            'social_identity_id' => $socialIdentityId,
            'issued_at'          => (string)$issuedAt,
            'expires_at'         => (string)$expiresAt,
            'phone_hash'         => '',
            'phone_verified'     => '0',
            'verified_phone'     => '',
        ]);
        $this->redis->expire($tokenKey, $ttlSeconds);
        $this->redis->set($identityKey, $tokenHash, $ttlSeconds);
        $this->redis->exec();

        return [
            'bind_token'            => $bindToken,
            'bind_token_expires_at' => $expiresAt,
        ];
    }

    public function verify(string $bindToken): ?SocialBindSession
    {
        $bindToken = trim($bindToken);
        if ($bindToken === '') {
            return null;
        }

        $tokenHash = hash('sha256', $bindToken);
        $data = $this->redis->hGetAll(self::TOKEN_PREFIX . $tokenHash);
        if (!is_array($data) || $data === []) {
            return null;
        }

        $identityId = (string)($data['social_identity_id'] ?? '');
        $currentHash = $identityId !== '' ? $this->redis->get(self::IDENTITY_PREFIX . $identityId) : false;
        if (!is_string($currentHash) || !hash_equals($tokenHash, $currentHash)) {
            return null;
        }

        return new SocialBindSession(
            tokenHash: $tokenHash,
            socialIdentityId: $identityId,
            expiresAt: (int)($data['expires_at'] ?? 0),
            phoneHash: ($phoneHash = (string)($data['phone_hash'] ?? '')) !== '' ? $phoneHash : null,
            phoneVerified: (string)($data['phone_verified'] ?? '0') === '1',
            verifiedPhone: ($verifiedPhone = (string)($data['verified_phone'] ?? '')) !== '' ? $verifiedPhone : null,
            issuedAt: (int)($data['issued_at'] ?? 0),
        );
    }

    public function setPhone(string $bindToken, string $phone): SocialBindSession
    {
        $session = $this->requireSession($bindToken);
        $phoneHash = hash('sha256', $phone);
        $updated = (int)$this->redis->eval(<<<'LUA'
if redis.call('GET', KEYS[2]) ~= ARGV[1] or redis.call('EXISTS', KEYS[1]) == 0 then return 0 end
local current = redis.call('HGET', KEYS[1], 'phone_hash')
if current and current ~= '' and current ~= ARGV[2] then return 2 end
redis.call('HSET', KEYS[1], 'phone_hash', ARGV[2])
return 1
LUA, [self::TOKEN_PREFIX . $session->tokenHash, self::IDENTITY_PREFIX . $session->socialIdentityId,
            $session->tokenHash, $phoneHash], 2);
        if ($updated !== 1) {
            $error = $updated === 2 ? ErrorCode::AUTH_SOCIAL_BIND_PHONE_CHANGED : ErrorCode::AUTH_SOCIAL_BIND_TOKEN_INVALID;
            throw new RuntimeException($error->message(), $error->value);
        }

        return $this->requireSession($bindToken);
    }

    public function assertPhone(string $bindToken, string $phone): SocialBindSession
    {
        $session = $this->requireSession($bindToken);
        $phoneHash = hash('sha256', $phone);
        if ($session->phoneHash === null || !hash_equals($session->phoneHash, $phoneHash)) {
            throw new RuntimeException(
                ErrorCode::AUTH_SOCIAL_BIND_PHONE_CHANGED->message(),
                ErrorCode::AUTH_SOCIAL_BIND_PHONE_CHANGED->value
            );
        }

        return $session;
    }

    public function markPhoneVerified(string $bindToken, string $phone): SocialBindSession
    {
        $session = $this->setPhone($bindToken, $phone);
        $updated = $this->redis->eval(<<<'LUA'
if redis.call('GET', KEYS[2]) ~= ARGV[1] or redis.call('EXISTS', KEYS[1]) == 0 then return 0 end
if redis.call('HGET', KEYS[1], 'phone_hash') ~= ARGV[2] then return 0 end
local now = tonumber(redis.call('TIME')[1])
local expires = math.max(tonumber(redis.call('HGET', KEYS[1], 'expires_at') or '0'), now + 300)
redis.call('HSET', KEYS[1], 'phone_verified', '1', 'verified_phone', ARGV[3], 'expires_at', expires)
redis.call('EXPIREAT', KEYS[1], expires)
redis.call('EXPIREAT', KEYS[2], expires)
return 1
LUA, [self::TOKEN_PREFIX . $session->tokenHash, self::IDENTITY_PREFIX . $session->socialIdentityId,
            $session->tokenHash, hash('sha256', $phone), $phone], 2);
        if ($updated !== 1) {
            throw new RuntimeException(ErrorCode::AUTH_SOCIAL_BIND_TOKEN_INVALID->message(), ErrorCode::AUTH_SOCIAL_BIND_TOKEN_INVALID->value);
        }

        return $this->requireSession($bindToken);
    }

    public function consume(string $bindToken): void
    {
        $session = $this->verify($bindToken);
        if ($session === null) {
            return;
        }

        $this->redis->eval(<<<'LUA'
redis.call('DEL', KEYS[1])
if redis.call('GET', KEYS[2]) == ARGV[1] then redis.call('DEL', KEYS[2]) end
return 1
LUA, [self::TOKEN_PREFIX . $session->tokenHash, self::IDENTITY_PREFIX . $session->socialIdentityId,
            $session->tokenHash], 2);
    }

    private function requireSession(string $bindToken): SocialBindSession
    {
        $session = $this->verify($bindToken);
        if ($session === null) {
            throw new RuntimeException(
                ErrorCode::AUTH_SOCIAL_BIND_TOKEN_INVALID->message(),
                ErrorCode::AUTH_SOCIAL_BIND_TOKEN_INVALID->value
            );
        }

        return $session;
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
