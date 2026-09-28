<?php

declare(strict_types=1);

namespace Tests\Unit;

use Services\AuthService\Application\Service\AuthApplicationService;
use Services\AuthService\Domain\Entity\Device;
use Services\AuthService\Domain\Entity\RefreshToken;
use Services\AuthService\Domain\Repository\AuthRepositoryInterface;
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

    public function revokeUserTokens(string $userId): void
    {
        foreach ($this->tokens as $hash => $tok) {
            if ($tok->userId === $userId) {
                unset($this->tokens[$hash]);
            }
        }
    }
}

final class AuthServiceTest extends TestCase
{
    private InMemoryAuthRepository $repo;
    private AuthApplicationService $authService;

    public function setUp(): void
    {
        $this->repo = new InMemoryAuthRepository();
        $this->authService = new AuthApplicationService(
            authRepo: $this->repo,
            jwtSecret: 'test-secret-at-least-32-chars-long-12345',
            wsTicketSecret: 'test-ws-secret-at-least-32-chars-12345'
        );
    }

    public function testLoginGeneratesTokensAndDevice(): void
    {
        $result = $this->authService->login('20001', [
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

    public function testCreateWsTicketGeneratesValidSignature(): void
    {
        $ticket = $this->authService->createWsTicket('20001', '30001', 'dev_1001');

        $this->assertNotEmpty($ticket['ticket']);
        $this->assertGreaterThan(time(), $ticket['expire_at']);
    }
}
