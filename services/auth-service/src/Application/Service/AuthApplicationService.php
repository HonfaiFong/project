<?php

declare(strict_types=1);

namespace Services\AuthService\Application\Service;

use Contracts\Errors\ErrorCode;
use RuntimeException;
use Services\AuthService\Domain\Auth\CredentialVerifierInterface;
use Services\AuthService\Domain\Client\UserDirectoryInterface;
use Services\AuthService\Domain\Repository\AccessTokenRevocationStoreInterface;
use Services\AuthService\Domain\Repository\AuthRepositoryInterface;
use Services\AuthService\Infrastructure\Token\JwtTokenCodec;
use Services\AuthService\Infrastructure\Token\WsTicketIssuer;

final class AuthApplicationService
{
    private readonly JwtTokenCodec $jwtCodec;
    private readonly WsTicketIssuer $wsTicketIssuer;

    public function __construct(
        private readonly AuthRepositoryInterface $authRepo,
        string|JwtTokenCodec $jwtSecret,
        string|WsTicketIssuer $wsTicketSecret,
        private readonly CredentialVerifierInterface $credentialVerifier,
        private readonly UserDirectoryInterface $userDirectory,
        private readonly int $accessTokenTtl = 7200,      // 2 hours
        private readonly int $refreshTokenTtl = 2592000,   // 30 days
        int $wsTicketTtl = 60,                            // 60 seconds per spec line 1504
        private readonly ?AccessTokenRevocationStoreInterface $accessTokenRevocationStore = null
    ) {
        $this->jwtCodec = is_string($jwtSecret) ? new JwtTokenCodec($jwtSecret) : $jwtSecret;
        $this->wsTicketIssuer = is_string($wsTicketSecret) ? new WsTicketIssuer($wsTicketSecret, $wsTicketTtl) : $wsTicketSecret;
    }

    /**
     * Authenticates public credentials (phone + verification code/password),
     * resolves or registers the user in UserService, and issues session tokens.
     *
     * @param array{device_uuid: string, platform: string, app_version: string, push_token?: ?string, ip?: ?string} $deviceInfo
     * @return array{user_id: string, access_token: string, refresh_token: string, access_token_expires_at: int, refresh_token_expires_at: int, is_new_user: bool}
     */
    public function login(
        string $phone,
        string $code,
        array $deviceInfo,
        ?string $password = null
    ): array {
        $this->credentialVerifier->verify($phone, [
            'code'     => $code,
            'password' => $password ?? '',
        ]);

        $userRes = $this->userDirectory->findOrCreateByPhone($phone);
        $userId = $userRes['user_id'];
        $isNewUser = $userRes['is_new_user'];

        if ($userId === '0' || $userId === '') {
            throw new RuntimeException('Failed to resolve user', ErrorCode::USER_NOT_FOUND->value);
        }

        return $this->issueTokenForUser($userId, $deviceInfo, $isNewUser);
    }

    /**
     * Issues access token and refresh token directly for an already authenticated user.
     * Used internally by login().
     *
     * @param array{device_uuid: string, platform: string, app_version: string, push_token?: ?string, ip?: ?string} $deviceInfo
     * @return array{user_id: string, access_token: string, refresh_token: string, access_token_expires_at: int, refresh_token_expires_at: int, is_new_user: bool}
     */
    private function issueTokenForUser(string $userId, array $deviceInfo, bool $isNewUser = false): array
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

        $accessToken = $this->jwtCodec->encode([
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

        $accessToken = $this->jwtCodec->encode([
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
                $payload = $this->jwtCodec->decode($rawAccessToken);
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
            $payload = $this->jwtCodec->decode($token);

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
        return $this->wsTicketIssuer->issue($userId, $personaId, $deviceId);
    }
}
