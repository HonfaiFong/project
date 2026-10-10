<?php

declare(strict_types=1);

namespace Services\AuthService\Infrastructure\Auth;

use Contracts\Errors\ErrorCode;
use RuntimeException;
use Services\AuthService\Domain\Auth\CredentialVerifierInterface;
use Throwable;

/**
 * 有意不声明为 final，便于测试及后续调用方用桩替代；所有属性均已声明为 readonly。
 *
 * @noinspection PhpClassCanBeReadonlyInspection
 */
class LoginCredentialVerifier implements CredentialVerifierInterface
{
    /**
     * @param callable(string, string, string): bool|null $provider
     */
    public function __construct(
        private readonly string $environment,
        private readonly ?string $developmentCode = null,
        private readonly mixed $provider = null,
        private readonly bool $developmentEnabled = false,
    ) {
    }

    public static function fromEnvironment(?array $environment = null): self
    {
        $environment ??= $_ENV + $_SERVER;
        $appEnvironment = strtolower(trim((string)($environment['APP_ENV'] ?? getenv('APP_ENV') ?: 'production')));
        $developmentCode = self::firstNonEmpty($environment);
        $developmentEnabled = filter_var(
            (string)($environment['ENABLE_DEV_LOGIN'] ?? getenv('ENABLE_DEV_LOGIN') ?: 'false'),
            FILTER_VALIDATE_BOOL
        );

        return new self($appEnvironment, $developmentCode, null, $developmentEnabled);
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
        } catch (Throwable) {
            throw $this->invalidCredentials();
        }

        if ($verified !== true) {
            throw $this->invalidCredentials();
        }
    }

    public function isDevelopment(): bool
    {
        return $this->developmentEnabled
            && in_array($this->environment, ['local', 'development', 'dev', 'testing'], true);
    }

    public function hasProvider(): bool
    {
        return is_callable($this->provider);
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
     */
    private static function firstNonEmpty(array $environment): ?string
    {
        $keys = [
            'AUTH_DEV_VERIFICATION_CODE',
            'AUTH_DEV_LOGIN_CODE',
            'DEV_VERIFICATION_CODE',
            'DEV_LOGIN_CODE',
        ];
        foreach ($keys as $key) {
            $value = trim((string)($environment[$key] ?? getenv($key) ?: ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
