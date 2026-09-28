<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Auth;

use Contracts\Errors\ErrorCode;
use RuntimeException;

/**
 * Verifies the credential presented to the public login endpoint.
 *
 * The development code is deliberately opt-in. A production gateway must be
 * given a real verifier by the composition root before it can issue tokens.
 */
final class LoginCredentialVerifier
{
    /**
     * @param callable(string, string, string): bool|null $provider
     */
    public function __construct(
        private readonly string $environment,
        private readonly ?string $developmentCode = null,
        private readonly mixed $provider = null,
    ) {
    }

    public static function fromEnvironment(?array $environment = null): self
    {
        $environment ??= $_ENV + $_SERVER;
        $appEnvironment = strtolower(trim((string)($environment['APP_ENV'] ?? getenv('APP_ENV') ?: 'production')));
        $developmentCode = self::firstNonEmpty($environment, [
            'AUTH_DEV_VERIFICATION_CODE',
            'AUTH_DEV_LOGIN_CODE',
            'DEV_VERIFICATION_CODE',
            'DEV_LOGIN_CODE',
        ]);

        return new self($appEnvironment, $developmentCode);
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public function verify(string $phone, array $credentials): void
    {
        $code = trim((string)($credentials['code'] ?? ''));
        $password = (string)($credentials['password'] ?? '');

        if ($this->isDevelopment()) {
            if ($this->developmentCode === null || $code === '' || !hash_equals($this->developmentCode, $code)) {
                throw $this->invalidCredentials();
            }

            return;
        }

        if (!is_callable($this->provider)) {
            throw $this->invalidCredentials();
        }

        try {
            $verified = ($this->provider)($phone, $code, $password);
        } catch (\Throwable) {
            throw $this->invalidCredentials();
        }

        if ($verified !== true) {
            throw $this->invalidCredentials();
        }
    }

    public function isDevelopment(): bool
    {
        return in_array($this->environment, ['local', 'development', 'dev', 'testing'], true);
    }

    private function invalidCredentials(): RuntimeException
    {
        return new RuntimeException(
            ErrorCode::AUTH_INVALID_CREDENTIALS->message(),
            ErrorCode::AUTH_INVALID_CREDENTIALS->value
        );
    }

    /**
     * @param array<string, mixed> $environment
     * @param array<string> $keys
     */
    private static function firstNonEmpty(array $environment, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string)($environment[$key] ?? getenv($key) ?: ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
