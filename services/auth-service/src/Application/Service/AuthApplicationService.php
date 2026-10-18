<?php

declare(strict_types=1);

namespace Services\AuthService\Application\Service;

use Contracts\Errors\ErrorCode;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Packages\Phone\PhoneNormalizer;
use Random\RandomException;
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
use Throwable;

final readonly class AuthApplicationService
{
    public function __construct(
        private AuthRepositoryInterface $authRepo,
        private JwtTokenCodec $jwtCodec,
        private WsTicketIssuer $wsTicketIssuer,
        private CredentialVerifierInterface $credentialVerifier,
        private UserDirectoryInterface $userDirectory,
        private int $accessTokenTtl = 7200,      // 2 小时
        private int $refreshTokenTtl = 2592000,   // 30 天
        private ?AccessTokenRevocationStoreInterface $accessTokenRevocationStore = null,
        private ?SocialAuthProviderRegistry $socialProviderRegistry = null,
        private ?SocialIdentityRepositoryInterface $socialIdentityRepository = null,
        private ?PhoneNormalizer $phoneNormalizer = null,
        private bool $phoneLoginEnabled = false,
        // Keep at least the maximum historical access-token TTL during configuration rollouts.
        private int $accessTokenRevocationTtl = 7200,
    ) {
    }

    /**
     * 认证公共凭据（手机号 + 验证码或密码），在 UserService 中查找或注册用户，并签发会话令牌。
     *
     * @param array{device_uuid: string, platform: string, app_version: string, push_token?: ?string, ip?: ?string} $deviceInfo
     * @return array{user_id: string, access_token: string, refresh_token: string, access_token_expires_at: int, refresh_token_expires_at: int, is_new_user: bool}
     * @throws Exception UserService 用户目录传输失败且未进入已映射状态路径时抛出
     * @throws RandomException CSPRNG 无法生成设备提示或刷新令牌时抛出
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
     * 验证 OAuth 并恢复幂等注册流程，然后签发会话。
     *
     * @param array{device_uuid: string, platform: string, app_version: string, push_token?: ?string, ip?: ?string} $deviceInfo
     * @return array{state: string, login: array<string, mixed>}
     * @throws Exception UserService 用户目录传输失败且未进入已映射状态路径时抛出
     * @throws RandomException CSPRNG 无法生成设备提示或刷新令牌时抛出
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

        // RPC 前先持久化注册意图；即使调用超时，也不能让手动绑定抢占可能已在 UserService 事务中提交的身份。
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
        // 外部请求可能持续数秒，完成后须重新检查。
        if (!$this->verifyToken($accessToken)['valid']) {
            throw new RuntimeException(ErrorCode::AUTH_UNAUTHORIZED->message(), ErrorCode::AUTH_UNAUTHORIZED->value);
        }
        $this->socialIdentityRepository->bindToUser($identity->id, $userId);

        return ['user_id' => $userId, 'provider' => $provider->value];
    }

    /**
     * @deprecated 手机号绑定已不再是公共认证流程。
     *
     * 保留废弃方法签名中的参数，使现有调用（包括命名参数调用）仍可编译，但所有调用都会被拒绝。
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public function sendSocialPhoneCode(string $bindToken, string $phone, array $context = []): array
    {
        throw new RuntimeException(ErrorCode::AUTH_UNAUTHORIZED->message(), ErrorCode::AUTH_UNAUTHORIZED->value);
    }

    /**
     * @deprecated 手机号绑定已不再是公共认证流程。
     *
     * 保留废弃方法签名中的参数，使现有调用（包括命名参数调用）仍可编译，但所有调用都会被拒绝。
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public function bindSocialPhone(string $bindToken, string $phone, string $code, array $deviceInfo): array
    {
        throw new RuntimeException(ErrorCode::AUTH_UNAUTHORIZED->message(), ErrorCode::AUTH_UNAUTHORIZED->value);
    }

    /**
     * 直接为已认证用户签发访问令牌和刷新令牌。
     * 由 login() 内部调用。
     *
     * @param array{device_uuid: string, platform: string, app_version: string, push_token?: ?string, ip?: ?string} $deviceInfo
     * @return array{user_id: string, access_token: string, refresh_token: string, access_token_expires_at: int, refresh_token_expires_at: int, is_new_user: bool}
     * @throws Exception UserService 用户目录传输失败且未进入已映射状态路径时抛出
     * @throws RandomException CSPRNG 无法生成设备提示或刷新令牌时抛出
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

        // Fractional NumericDate keeps a later login distinct from a same-second device revocation.
        $accessToken = $this->jwtCodec->encode([
            'jti'       => bin2hex(random_bytes(16)),
            'sub'       => $userId,
            'device_id' => $device->id,
            'iat'       => microtime(true),
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
     * 为兼容传输层签名而接受设备提示，但令牌轮换使用已存储刷新令牌记录中的设备。
     *
     * @param array{device_uuid?: string, platform?: string, app_version?: string} $deviceInfo
     * @return array{access_token: string, refresh_token: string, access_token_expires_at: int, refresh_token_expires_at: int}
     * @throws Exception UserService 用户目录传输失败且未进入已映射状态路径时抛出
     * @throws RandomException CSPRNG 无法生成轮换后的刷新令牌时抛出
     *
     * @noinspection PhpUnusedParameterInspection
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

        // Capture before the locked rotation: a logout that commits next must also revoke this access token.
        $issuedAt = microtime(true);
        $record = $this->authRepo->rotateRefreshToken(
            tokenHash: $tokenHash,
            newTokenHash: $newTokenHash,
            newExpiresAt: gmdate('Y-m-d H:i:s.v', $refreshExpiresAt)
        );

        if (!$record) {
            throw new RuntimeException('Token invalid or expired', ErrorCode::AUTH_TOKEN_EXPIRED->value);
        }

        $accessToken = $this->jwtCodec->encode([
            'jti'       => bin2hex(random_bytes(16)),
            'sub'       => $record->userId,
            'device_id' => $record->deviceId,
            'iat'       => $issuedAt,
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
        if ($record === null) {
            return false;
        }

        $payload = null;
        if ($rawAccessToken !== null && $rawAccessToken !== '') {
            try {
                $payload = $this->jwtCodec->decode($rawAccessToken);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    ErrorCode::AUTH_UNAUTHORIZED->message(),
                    ErrorCode::AUTH_UNAUTHORIZED->value,
                    $e,
                );
            }

            if (
                (string)($payload['sub'] ?? '') !== $record->userId
                || (string)($payload['device_id'] ?? '') !== $record->deviceId
                || !isset($payload['iat']) || !is_numeric($payload['iat'])
            ) {
                throw new RuntimeException(
                    ErrorCode::AUTH_UNAUTHORIZED->message(),
                    ErrorCode::AUTH_UNAUTHORIZED->value,
                );
            }
        }

        if ($record->loggedOutAt === null) {
            $this->authRepo->revokeRefreshToken($tokenHash);
            // The conditional UPDATE may have lost to another logout. Always use the persisted winner.
            $record = $this->authRepo->findRefreshToken($tokenHash);
        }

        if ($record === null || $record->loggedOutAt === null) {
            // Rotation is not logout. The client must use its current refresh credential.
            return false;
        }

        $utc = new DateTimeZone('UTC');
        $revokedAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $record->loggedOutAt, $utc)
            ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $record->loggedOutAt, $utc);
        if ($revokedAt === false) {
            throw new RuntimeException(ErrorCode::SERVICE_UNAVAILABLE->message(), ErrorCode::SERVICE_UNAVAILABLE->value);
        }
        $revocationTimestamp = (float)$revokedAt->format('U.u');

        // A historical refresh token only authorizes recovery of its original logout, not a newer bearer.
        if ($payload !== null && (float)$payload['iat'] > $revocationTimestamp) {
            throw new RuntimeException(ErrorCode::AUTH_UNAUTHORIZED->message(), ErrorCode::AUTH_UNAUTHORIZED->value);
        }

        $remainingTtl = (int)ceil($revocationTimestamp + max($this->accessTokenTtl, $this->accessTokenRevocationTtl) - microtime(true));
        if ($remainingTtl <= 0) {
            return true;
        }

        if ($this->accessTokenRevocationStore !== null) {
            $this->accessTokenRevocationStore->revokeDevice(
                $record->deviceId,
                $remainingTtl,
                $revocationTimestamp
            );

            if ($payload !== null) {
                $this->accessTokenRevocationStore->revokeToken(
                    hash('sha256', $rawAccessToken),
                    max(1, (int)$payload['exp'] - time())
                );
            }
        }

        return true;
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
                (float)($payload['iat'] ?? 0)
            )) {
                return ['valid' => false];
            }

            // 拒绝设备已被重新分配给其他用户的历史会话。
            if (!$this->authRepo->deviceBelongsToUser($userId, (string)($payload['device_id'] ?? ''))) {
                return ['valid' => false];
            }
        } catch (Throwable $e) {
            throw new RuntimeException(
                ErrorCode::SERVICE_UNAVAILABLE->message(),
                ErrorCode::SERVICE_UNAVAILABLE->value,
                $e,
            );
        }

        try {
            $this->assertUserCanAuthenticate($userId);
        } catch (Throwable $e) {
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

    /** @throws RandomException CSPRNG 无法生成 WebSocket 票据时抛出 */
    public function createWsTicket(string $userId, string $personaId, string $deviceId): array
    {
        return $this->wsTicketIssuer->issue($userId, $personaId, $deviceId);
    }

    /**
     * @param array{device_uuid: string, platform: string, app_version: string, push_token?: ?string, ip?: ?string} $deviceInfo
     * @throws Exception UserService 用户目录传输失败且未进入已映射状态路径时抛出
     * @throws RandomException CSPRNG 无法生成设备提示或刷新令牌时抛出
     */
    private function authenticatedSocialLogin(SocialIdentity $identity, array $deviceInfo): array
    {
        if ($identity->userId === null || $identity->userId === '') {
            throw new RuntimeException(
                ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->message(),
                ErrorCode::AUTH_SOCIAL_BIND_CONFLICT->value
            );
        }

        $login = $this->issueTokenForUser($identity->userId, $deviceInfo);
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

    /** @throws Exception UserService 用户目录传输失败且未进入已映射状态路径时抛出 */
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
