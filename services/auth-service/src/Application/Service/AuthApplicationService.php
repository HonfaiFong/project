<?php

declare(strict_types=1);

namespace Services\AuthService\Application\Service;

use Contracts\Errors\ErrorCode;
use RuntimeException;
use Services\AuthService\Domain\Repository\AccessTokenRevocationStoreInterface;
use Services\AuthService\Domain\Repository\AuthRepositoryInterface;

final class AuthApplicationService
{
    public function __construct(
        private readonly AuthRepositoryInterface $authRepo,
        private readonly string $jwtSecret,
        private readonly string $wsTicketSecret,
        private readonly int $accessTokenTtl = 7200,      // 2 hours
        private readonly int $refreshTokenTtl = 2592000,   // 30 days
        private readonly int $wsTicketTtl = 60,            // 60 seconds per spec line 1504
        private readonly ?AccessTokenRevocationStoreInterface $accessTokenRevocationStore = null
    ) {
    }

    /**
     * @param array{device_uuid: string, platform: string, app_version: string, push_token?: ?string, ip?: ?string} $deviceInfo
     * @return array{user_id: string, access_token: string, refresh_token: string, access_token_expires_at: int, refresh_token_expires_at: int, is_new_user: bool}
     */
    public function login(string $userId, array $deviceInfo, bool $isNewUser = false): array
    {
        $device = $this->authRepo->findOrCreateDevice(
            userId: $userId,
            deviceUuid: $deviceInfo['device_uuid'] ?: 'uuid-' . bin2hex(random_bytes(8)),
            platform: $deviceInfo['platform'] ?: 'unknown',
            appVersion: $deviceInfo['app_version'] ?: '1.0.0',
            pushToken: $deviceInfo['push_token'] ?? null,
            ip: $deviceInfo['ip'] ?? null
        );

        $now = time();
        $accessExpiresAt = $now + $this->accessTokenTtl;
        $refreshExpiresAt = $now + $this->refreshTokenTtl;

        $accessToken = $this->generateJwt([
            'sub'       => $userId,
            'device_id' => $device->id,
            'iat'       => $now,
            'exp'       => $accessExpiresAt,
        ]);

        $rawRefreshToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawRefreshToken);

        $this->authRepo->createRefreshToken(
            userId: $userId,
            deviceId: $device->id,
            tokenHash: $tokenHash,
            expiresAt: gmdate('Y-m-d H:i:s.v', $refreshExpiresAt)
        );

        return [
            'user_id'                  => $userId,
            'access_token'             => $accessToken,
            'refresh_token'            => $rawRefreshToken,
            'access_token_expires_at'  => $accessExpiresAt,
            'refresh_token_expires_at' => $refreshExpiresAt,
            'is_new_user'              => $isNewUser,
        ];
    }

    /**
     * @param array{device_uuid?: string, platform?: string, app_version?: string} $deviceInfo
     * @return array{access_token: string, refresh_token: string, access_token_expires_at: int, refresh_token_expires_at: int}
     */
    public function refreshToken(string $rawRefreshToken, array $deviceInfo = []): array
    {
        $tokenHash = hash('sha256', $rawRefreshToken);

        $now = time();
        $accessExpiresAt = $now + $this->accessTokenTtl;
        $refreshExpiresAt = $now + $this->refreshTokenTtl;

        $newRawRefreshToken = bin2hex(random_bytes(32));
        $newTokenHash = hash('sha256', $newRawRefreshToken);

        $record = $this->authRepo->rotateRefreshToken(
            tokenHash: $tokenHash,
            newTokenHash: $newTokenHash,
            newExpiresAt: gmdate('Y-m-d H:i:s.v', $refreshExpiresAt)
        );

        if (!$record) {
            throw new RuntimeException('Token invalid or expired', ErrorCode::AUTH_TOKEN_EXPIRED->value);
        }

        $accessToken = $this->generateJwt([
            'sub'       => $record->userId,
            'device_id' => $record->deviceId,
            'iat'       => $now,
            'exp'       => $accessExpiresAt,
        ]);

        return [
            'access_token'             => $accessToken,
            'refresh_token'            => $newRawRefreshToken,
            'access_token_expires_at'  => $accessExpiresAt,
            'refresh_token_expires_at' => $refreshExpiresAt,
        ];
    }

    public function logout(string $rawRefreshToken, ?string $rawAccessToken = null): bool
    {
        $tokenHash = hash('sha256', $rawRefreshToken);
        $record = $this->authRepo->findRefreshToken($tokenHash);
        $refreshRevoked = $this->authRepo->revokeRefreshToken($tokenHash);
        $accessRevoked = false;

        if ($this->accessTokenRevocationStore !== null && $record !== null) {
            $this->accessTokenRevocationStore->revokeDevice($record->deviceId, $this->accessTokenTtl);
            $accessRevoked = true;
        }

        if ($this->accessTokenRevocationStore !== null && $rawAccessToken !== null && $rawAccessToken !== '') {
            $payload = null;
            try {
                $payload = $this->verifyJwt($rawAccessToken);
            } catch (\Throwable) {
                // An invalid access token cannot be used for authentication and needs no blacklist entry.
            }

            if (
                $payload !== null
                && ($record === null
                    || ((string)($payload['sub'] ?? '') === $record->userId
                        && (string)($payload['device_id'] ?? '') === $record->deviceId))
            ) {
                $this->accessTokenRevocationStore->revokeToken(
                    hash('sha256', $rawAccessToken),
                    max(1, (int)$payload['exp'] - time())
                );
                $accessRevoked = true;
            }
        }

        return $refreshRevoked || $accessRevoked;
    }

    /**
     * @return array{valid: bool, user_id?: string, device_id?: string}
     */
    public function verifyToken(string $token): array
    {
        try {
            $payload = $this->verifyJwt($token);

            if ($this->accessTokenRevocationStore?->isRevoked(
                hash('sha256', $token),
                (string)($payload['device_id'] ?? ''),
                (int)($payload['iat'] ?? 0)
            )) {
                return ['valid' => false];
            }

            return [
                'valid'     => true,
                'user_id'   => (string)$payload['sub'],
                'device_id' => (string)($payload['device_id'] ?? ''),
            ];
        } catch (\Throwable) {
            return ['valid' => false];
        }
    }

    public function createWsTicket(string $userId, string $personaId, string $deviceId): array
    {
        $expireAt = time() + $this->wsTicketTtl;
        $nonce = bin2hex(random_bytes(8));

        // Format: user_id:persona_id:device_id:nonce:expire_at:signature
        $signData = sprintf('%s:%s:%s:%s:%d', $userId, $personaId, $deviceId, $nonce, $expireAt);
        $signature = hash_hmac('sha256', $signData, $this->wsTicketSecret);

        // base64url (RFC 4648 §5, unpadded): the ticket travels in the
        // `GET /ws?ticket=` query string, where standard base64's `+/=`
        // characters get mangled by URL query decoding.
        $ticket = $this->base64UrlEncode(sprintf('%s:%s', $signData, $signature));

        return [
            'ticket'    => $ticket,
            'expire_at' => $expireAt,
        ];
    }

    private function generateJwt(array $payload): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $b64Header = $this->base64UrlEncode((string)json_encode($header));
        $b64Payload = $this->base64UrlEncode((string)json_encode($payload));

        $signature = hash_hmac('sha256', "{$b64Header}.{$b64Payload}", $this->jwtSecret, true);
        $b64Sig = $this->base64UrlEncode($signature);

        return "{$b64Header}.{$b64Payload}.{$b64Sig}";
    }

    private function verifyJwt(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed JWT');
        }

        [$b64Header, $b64Payload, $b64Sig] = $parts;
        $expectedSig = hash_hmac('sha256', "{$b64Header}.{$b64Payload}", $this->jwtSecret, true);

        if (!hash_equals($expectedSig, $this->base64UrlDecode($b64Sig))) {
            throw new RuntimeException('Invalid signature');
        }

        $payload = json_decode($this->base64UrlDecode($b64Payload), true);
        if (!is_array($payload) || !isset($payload['exp']) || $payload['exp'] < time()) {
            throw new RuntimeException('Token expired or payload invalid');
        }

        return $payload;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return (string)base64_decode(strtr($data, '-_', '+/'));
    }
}
