<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Errors\ErrorCode;
use RuntimeException;
use Services\AuthService\Infrastructure\Auth\LoginCredentialVerifier;
use Tests\TestCase;

final class LoginCredentialVerifierTest extends TestCase
{
    public function testDevelopmentLoginRequiresConfiguredVerificationCode(): void
    {
        $verifier = new LoginCredentialVerifier('local', 'dev-only-code', null, true);

        $this->assertThrows(fn () => $verifier->verify('+8613800000000', []));
        $this->assertThrows(fn () => $verifier->verify('+8613800000000', ['code' => 'wrong']));

        $verifier->verify('+8613800000000', ['code' => 'dev-only-code']);
    }

    public function testNonDevelopmentLoginRequiresRealProvider(): void
    {
        $withoutProvider = new LoginCredentialVerifier('production');
        $this->assertThrows(fn () => $withoutProvider->verify('13800000000', ['code' => '123456']));

        $provider = new LoginCredentialVerifier(
            'production',
            null,
            static fn (string $phone, string $code, string $password): bool =>
                $phone === '13800000000' && $code === '654321' && $password === ''
        );
        $provider->verify('13800000000', ['code' => '654321']);
    }

    public function testEnvironmentBasedDevelopmentLoginRequiresExplicitOptIn(): void
    {
        $disabled = LoginCredentialVerifier::fromEnvironment([
            'APP_ENV' => 'local',
            'DEV_LOGIN_CODE' => 'dev-only-code',
            'ENABLE_DEV_LOGIN' => 'false',
        ]);
        $this->assertThrows(fn () => $disabled->verify('+8613800000000', ['code' => 'dev-only-code']));

        $enabled = LoginCredentialVerifier::fromEnvironment([
            'APP_ENV' => 'local',
            'DEV_LOGIN_CODE' => 'dev-only-code',
            'ENABLE_DEV_LOGIN' => 'true',
        ]);
        $enabled->verify('+8613800000000', ['code' => 'dev-only-code']);
    }

    private function assertThrows(callable $callback): void
    {
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame(ErrorCode::AUTH_INVALID_CREDENTIALS->value, $e->getCode());
            return;
        }

        throw new RuntimeException('Expected credential verification to fail');
    }
}
