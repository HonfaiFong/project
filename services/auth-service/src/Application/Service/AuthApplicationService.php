<?php

declare(strict_types=1);

namespace Services\AuthService\Application\Service;

use Contracts\Errors\ErrorCode;
use Packages\Phone\PhoneNormalizer;
use RuntimeException;
use Services\AuthService\Domain\Auth\SocialProvider;
use Services\AuthService\Domain\Auth\CredentialVerifierInterface;
use Services\AuthService\Domain\Client\UserDirectoryInterface;
use Services\AuthService\Domain\Entity\SocialIdentity;
use Services\AuthService\Domain\Repository\AccessTokenRevocationStoreInterface;
use Services\AuthService\Domain\Repository\AuthRepositoryInterface;
use Services\AuthService\Domain\Repository\SocialIdentityRepositoryInterface;
use Services\AuthService\Infrastructure\OAuth\SocialAuthProviderRegistry;
use Services\AuthService\Infrastructure\Token\JwtTokenCodec;
use Services\AuthService\Infrastructure\Token\WsTicketIssuer;

final class AuthApplicationService
{
    public function __construct(
        private readonly AuthRepositoryInterface $authRepo,
        private readonly JwtTokenCodec $jwtCodec,
        private readonly WsTicketIssuer $wsTicketIssuer,
        private readonly CredentialVerifierInterface $credentialVerifier,
        private readonly UserDirectoryInterface $userDirectory,
        private readonly int $accessTokenTtl = 7200,      // 2 hours
        private readonly int $refreshTokenTtl = 2592000,   // 30 days
        private readonly ?AccessTokenRevocationStoreInterface $accessTokenRevocationStore = null,
        private readonly ?SocialAuthProviderRegistry $socialProviderRegistry = null,
        private readonly ?SocialIdentityRepositoryInterface $socialIdentityRepository = null,
        private readonly ?PhoneNormalizer $phoneNormalizer = null,
        private readonly bool $phoneLoginEnabled = false,
    ) {
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
        if (!$this->phoneLoginEnabled) {
            throw new RuntimeException(ErrorCode::AUTH_UNAUTHORIZED->message(), ErrorCode::AUTH_UNAUTHORIZED->value);
        }

        $phone = $this->normalizePhone($phone);
        $this->credentialVerifier->verify($phone, [
            'code'     => $code,
            'password' => $password ?? '',
        ]);

        $userRes = $this->userDirectory->findOrCreateByPhone($phone, 'phone');
        $userId = $userRes['user_id'];
        $isNewUser = $userRes['is_new_user'];

        if ($userId === '0' || $userId === '') {
            throw new RuntimeException('Failed to resolve user', ErrorCode::USER_NOT_FOUND->value);
        }

        return $this->issueTokenForUser($userId, $deviceInfo, $isNewUser);
    }

    /**
     * Verifies OAuth and resumes an idempotent registration before issuing a session.
     *
     * @param array{device_uuid: string, platform: string, app_version: string, push_token?: ?string, ip?: ?string} $deviceInfo
     * @return array{state: string, login: array<string, mixed>}
     */
    public function socialLogin(
        SocialProvider $provider,
        string $code,
        array $deviceInfo
    ): array {
        $this->requireSocialDependencies();

        $externalIdentity = $this->socialProviderRegistry->get($provider)->authenticate($code);
        $identity = $this->socialIdentityRepository->findByExternalIdentity($externalIdentity);

        if ($identity?->isDisabled()) {
            throw new RuntimeException(
                ErrorCode::AUTH_UNAUTHORIZED->message(),
                ErrorCode::AUTH_UNAUTHORIZED->value
            );
        }

        if ($identity?->isActive()) {
            return $this->authenticatedSocialLogin($identity, $deviceInfo);
        }

        $identity ??= $this->socialIdentityRepository->reserve($externalIdentity);
        if ($identity->isDisabled()) {
            throw new RuntimeException(
                ErrorCode::AUTH_UNAUTHORIZED->message(),
                ErrorCode::AUTH_UNAUTHORIZED->value
            );
        }
        if ($identity->isActive()) {
            return $this->authenticatedSocialLogin($identity, $deviceInfo);
        }

        // Persist intent before the RPC: a timeout must never let a manual bind
        // steal an identity whose UserService transaction may already have committed.
        $identity = $this->socialIdentityRepository->beginRegistration($identity->id);
        if ($identity->isActive()) {
            return $this->authenticatedSocialLogin($identity, $deviceInfo);
        }

        $user = $this->userDirectory->findOrCreateBySocialIdentity(
            'social:' . $identity->id,
            $identity->provider->value,
        );
        $userId = (string)$user['user_id'];
        if ($userId === '' || $userId === '0') {
            throw new RuntimeException(ErrorCode::USER_NOT_FOUND->message(), ErrorCode::USER_NOT_FOUND->value);
        }
        $this->assertUserCanAuthenticate($userId);
        $identity = $this->socialIdentityRepository->completeRegistration($identity->id, $userId);
        $login = $this->issueTokenForUser($userId, $deviceInfo, (bool)$user['is_new_user']);
        $this->socialIdentityRepository->touchLastLogin($identity->id);

        return ['state' => 'authenticated', 'login' => $login];
    }

    /** @return array{user_id: string, provider: string} */
    public function bindSocialIdentity(SocialProvider $provider, string $code, string $accessToken): array
    {
        $this->requireSocialDependencies();
        $session = $this->verifyToken($accessToken);
        if (!$session['valid']) {
            throw new RuntimeException(ErrorCode::AUTH_UNAUTHORIZED->message(), ErrorCode::AUTH_UNAUTHORIZED->value);
        }
        $userId = $session['user_id'];
        $externalIdentity = $this->socialProviderRegistry->get($provider)->authenticate($code);
        $identity = $this->socialIdentityRepository->reserve($externalIdentity);
        // Recheck after the external request, which may have taken several seconds.
        if (!$this->verifyToken($accessToken)['valid']) {
            throw new RuntimeException(ErrorCode::AUTH_UNAUTHORIZED->message(), ErrorCode::AUTH_UNAUTHORIZED->value);
        }
        $this->socialIdentityRepository->bindToUser($identity->id, $userId);

        return ['user_id' => $userId, 'provider' => $provider->value];
    }

    /** @deprecated Phone binding is no longer a public authentication flow. */
    public function sendSocialPhoneCode(string $bindToken, string $phone, array $context = []): array
    {
        throw new RuntimeException(ErrorCode::AUTH_UNAUTHORIZED->message(), ErrorCode::AUTH_UNAUTHORIZED->value);
    }

    /** @deprecated Phone binding is no longer a public authentication flow. */
    public function bindSocialPhone(string $bindToken, string $phone, string $code, array $deviceInfo): array
    {
        throw new RuntimeException(ErrorCode::AUTH_UNAUTHORIZED->message(), ErrorCode::AUTH_UNAUTHORIZED->value);
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
        $this->assertUserCanAuthenticate($userId);

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
        $record = $this->authRepo->findRefreshToken($tokenHash);
        if (!$record || !$record->isValid()) {
            throw new RuntimeException('Token invalid or expired', ErrorCode::AUTH_TOKEN_EXPIRED->value);
        }
        $this->assertUserCanAuthenticate($record->userId);

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
     * @phpstan-impure Reads current revocation and user state on every call.
     * @return array{valid: bool, user_id?: string, device_id?: string}
     */
    public function verifyToken(string $token): array
    {
        try {
            $payload = $this->jwtCodec->decode($token);
        } catch (RuntimeException) {
            return ['valid' => false];
        }

        $userId = (string)($payload['sub'] ?? '');
        if ($userId === '' || $userId === '0') {
            return ['valid' => false];
        }

        try {
            if ($this->accessTokenRevocationStore?->isRevoked(
                hash('sha256', $token),
                (string)($payload['device_id'] ?? ''),
                (int)($payload['iat'] ?? 0)
            )) {
                return ['valid' => false];
            }

            // Reject legacy sessions whose device was reassigned to another user.
            if (!$this->authRepo->deviceBelongsToUser($userId, (string)($payload['device_id'] ?? ''))) {
                return ['valid' => false];
            }
        } catch (\Throwable $e) {
            throw new RuntimeException(
                ErrorCode::SERVICE_UNAVAILABLE->message(),
                ErrorCode::SERVICE_UNAVAILABLE->value,
                $e,
            );
        }

        try {
            $this->assertUserCanAuthenticate($userId);
        } catch (\Throwable $e) {
            if (in_array($e->getCode(), [ErrorCode::USER_NOT_FOUND->value, ErrorCode::USER_BANNED->value], true)) {
                return ['valid' => false];
            }

            $error = $e->getCode() === ErrorCode::RPC_TIMEOUT->value
                ? ErrorCode::RPC_TIMEOUT
                : ErrorCode::SERVICE_UNAVAILABLE;
            throw new RuntimeException($error->message(), $error->value, $e);
        }

        return [
            'valid'     => true,
            'user_id'   => $userId,
            'device_id' => (string)($payload['device_id'] ?? ''),
        ];
    }

    public function createWsTicket(string $userId, string $personaId, string $deviceId): array
    {
        return $this->wsTicketIssuer->issue($userId, $personaId, $deviceId);
    }

    /** @param array{device_uuid: string, platform: string, app_version: string, push_token?: ?string, ip?: ?string} $deviceInfo */
    private function authenticatedSocialLogin(SocialIdentity $identity, array $deviceInfo): array
    {
        if ($identity->userId === null || $identity->userId === '') {
            throw new RuntimeException(
                ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->message(),
                ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->value
            );
        }

        $login = $this->issueTokenForUser($identity->userId, $deviceInfo, false);
        $this->socialIdentityRepository->touchLastLogin($identity->id);

        return [
            'state' => 'authenticated',
            'login' => $login,
        ];
    }

    private function requireSocialDependencies(): void
    {
        if (
            $this->socialProviderRegistry === null
            || $this->socialIdentityRepository === null
        ) {
            throw new RuntimeException(
                ErrorCode::SERVICE_UNAVAILABLE->message(),
                ErrorCode::SERVICE_UNAVAILABLE->value
            );
        }
    }

    private function normalizePhone(string $phone): string
    {
        return $this->phoneNormalizer?->normalize($phone) ?? trim($phone);
    }

    private function assertUserCanAuthenticate(string $userId): void
    {
        $user = $this->userDirectory->findById($userId);
        if ($user['status'] !== UserDirectoryInterface::STATUS_ACTIVE) {
            throw new RuntimeException(
                ErrorCode::USER_BANNED->message(),
                ErrorCode::USER_BANNED->value
            );
        }
    }
}
