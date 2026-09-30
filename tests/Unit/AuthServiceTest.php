<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Errors\ErrorCode;
use Packages\Logging\JsonLogger;
use Services\AuthService\Application\Service\AuthApplicationService;
use Services\AuthService\Domain\Client\UserDirectoryInterface;
use Services\AuthService\Domain\Entity\Device;
use Services\AuthService\Domain\Entity\RefreshToken;
use Services\AuthService\Domain\Repository\AccessTokenRevocationStoreInterface;
use Services\AuthService\Domain\Repository\AuthRepositoryInterface;
use Contracts\Gen\Auth\V1\DeviceInfo;
use Contracts\Gen\Auth\V1\LoginRequest;
use Services\AuthService\Infrastructure\Auth\LoginCredentialVerifier;
use Services\AuthService\Infrastructure\Token\JwtTokenCodec;
use Services\AuthService\Infrastructure\Token\WsTicketIssuer;
use Services\AuthService\Transport\Grpc\RoadRunnerAuthService;
use Spiral\RoadRunner\GRPC\Context;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\StatusCode;
use Tests\TestCase;

final class InMemoryAuthRepository implements AuthRepositoryInterface
{
    /** @var array<string, Device> */
    public array $devices = [];
    /** @var array<string, RefreshToken> */
    public array $tokens = [];

    public function findOrCreateDevice(
        string $userId,
        string $deviceUuid,
        string $platform,
        string $appVersion,
        ?string $pushToken,
        ?string $ip
    ): Device {
        $dev = new Device(
            id: 'dev_1001',
            userId: $userId,
            deviceUuid: $deviceUuid,
            platform: $platform,
            appVersion: $appVersion,
            pushToken: $pushToken
        );
        $this->devices[$deviceUuid] = $dev;
        return $dev;
    }

    public function createRefreshToken(
        string $userId,
        string $deviceId,
        string $tokenHash,
        string $expiresAt
    ): RefreshToken {
        $tok = new RefreshToken(
            id: 'tok_2001',
            userId: $userId,
            deviceId: $deviceId,
            tokenHash: $tokenHash,
            expiresAt: $expiresAt
        );
        $this->tokens[$tokenHash] = $tok;
        return $tok;
    }

    public function rotateRefreshToken(
        string $tokenHash,
        string $newTokenHash,
        string $newExpiresAt
    ): ?RefreshToken {
        $record = $this->tokens[$tokenHash] ?? null;

        if (!$record || !$record->isValid()) {
            return null;
        }

        unset($this->tokens[$tokenHash]);
        $this->tokens[$newTokenHash] = new RefreshToken(
            id: 'tok_' . (2001 + count($this->tokens)),
            userId: $record->userId,
            deviceId: $record->deviceId,
            tokenHash: $newTokenHash,
            expiresAt: $newExpiresAt
        );

        return $record;
    }

    public function findRefreshToken(string $tokenHash): ?RefreshToken
    {
        return $this->tokens[$tokenHash] ?? null;
    }

    public function revokeRefreshToken(string $tokenHash): bool
    {
        if (isset($this->tokens[$tokenHash])) {
            unset($this->tokens[$tokenHash]);
            return true;
        }
        return false;
    }
}

final class InMemoryAccessTokenRevocationStore implements AccessTokenRevocationStoreInterface
{
    /** @var array<string, true> */
    public array $tokenHashes = [];

    /** @var array<string, int> */
    public array $deviceRevokedAt = [];

    public function revokeToken(string $tokenHash, int $ttl): void
    {
        $this->tokenHashes[$tokenHash] = true;
    }

    public function revokeDevice(string $deviceId, int $ttl): void
    {
        $this->deviceRevokedAt[$deviceId] = time();
    }

    public function isRevoked(string $tokenHash, string $deviceId, int $issuedAt): bool
    {
        return isset($this->tokenHashes[$tokenHash])
            || (isset($this->deviceRevokedAt[$deviceId]) && $issuedAt <= $this->deviceRevokedAt[$deviceId]);
    }
}

final class AuthServiceTest extends TestCase
{
    private InMemoryAuthRepository $repo;
    private InMemoryAccessTokenRevocationStore $revocationStore;
    private AuthApplicationService $authService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new InMemoryAuthRepository();
        $this->revocationStore = new InMemoryAccessTokenRevocationStore();
        $userDirectory = new class implements UserDirectoryInterface {
            public function findOrCreateByPhone(string $phone, string $registerChannel = 'default'): array
            {
                return [
                    'user_id'     => '20001',
                    'is_new_user' => false,
                ];
            }
        };
        $this->authService = new AuthApplicationService(
            authRepo: $this->repo,
            jwtCodec: new JwtTokenCodec('test-secret-at-least-32-chars-long-12345'),
            wsTicketIssuer: new WsTicketIssuer('test-ws-secret-at-least-32-chars-12345', 60),
            credentialVerifier: new LoginCredentialVerifier('local', 'dev-1234'),
            userDirectory: $userDirectory,
            accessTokenRevocationStore: $this->revocationStore
        );
    }

    public function testLoginAuthenticatesAndResolvesUser(): void
    {
        $result = $this->authService->login('+8613800000000', 'dev-1234', [
            'device_uuid' => 'uuid-123',
            'platform'    => 'ios',
            'app_version' => '1.0.0',
        ]);

        $this->assertNotEmpty($result['access_token']);
        $this->assertNotEmpty($result['refresh_token']);
        $this->assertSame('20001', $result['user_id']);

        // Verify generated JWT
        $verify = $this->authService->verifyToken($result['access_token']);
        $this->assertTrue($verify['valid']);
        $this->assertSame('20001', $verify['user_id']);
    }

    public function testLoginRejectsInvalidCredentials(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->authService->login('+8613800000000', 'wrong-code', [
            'device_uuid' => 'uuid-123',
            'platform'    => 'ios',
            'app_version' => '1.0.0',
        ]);
    }

    public function testRefreshTokenCanOnlyBeConsumedOnce(): void
    {
        $login = $this->authService->login('+8613800000000', 'dev-1234', [
            'device_uuid' => 'uuid-123',
            'platform'    => 'ios',
            'app_version' => '1.0.0',
        ]);

        $refresh = $this->authService->refreshToken($login['refresh_token']);

        $this->assertNotSame($login['refresh_token'], $refresh['refresh_token']);
        $this->assertArrayNotHasKey(
            hash('sha256', $login['refresh_token']),
            $this->repo->tokens
        );

        $this->expectException(\RuntimeException::class);
        $this->authService->refreshToken($login['refresh_token']);
    }

    public function testLogoutRevokesAccessTokenImmediately(): void
    {
        $login = $this->authService->login('+8613800000000', 'dev-1234', [
            'device_uuid' => 'uuid-123',
            'platform'    => 'ios',
            'app_version' => '1.0.0',
        ]);

        $this->assertTrue($this->authService->verifyToken($login['access_token'])['valid']);
        $this->assertTrue($this->authService->logout($login['refresh_token']));
        $this->assertFalse($this->authService->verifyToken($login['access_token'])['valid']);
    }

    public function testCreateWsTicketGeneratesValidSignature(): void
    {
        $ticket = $this->authService->createWsTicket('20001', '30001', 'dev_1001');

        $this->assertNotEmpty($ticket['ticket']);
        $this->assertGreaterThan(time(), $ticket['expire_at']);
    }

    public function testRoadRunnerAuthServiceLoginSuccess(): void
    {
        $service = new RoadRunnerAuthService($this->authService, new JsonLogger('test-auth'));
        $ctx = new Context(['metadata' => []]);
        $response = $service->Login($ctx, new LoginRequest([
            'phone' => '+8613800000000',
            'code'  => 'dev-1234',
            'device'=> new DeviceInfo([
                'device_uuid' => 'dev-uuid-test',
                'platform'    => 'android',
                'app_version' => '1.0.0',
            ]),
        ]));

        $this->assertSame('20001', (string)$response->getUserId());
        $this->assertNotEmpty($response->getAccessToken());
        $this->assertNotEmpty($response->getRefreshToken());
    }

    public function testRoadRunnerAuthServiceLoginFailsOnInvalidCode(): void
    {
        $service = new RoadRunnerAuthService($this->authService, new JsonLogger('test-auth'));
        $ctx = new Context(['metadata' => []]);
        $this->expectException(GRPCException::class);
        $this->expectExceptionCode(StatusCode::UNAUTHENTICATED);
        $service->Login($ctx, new LoginRequest([
            'phone' => '+8613800000000',
            'code'  => 'invalid-code',
        ]));
    }
}
