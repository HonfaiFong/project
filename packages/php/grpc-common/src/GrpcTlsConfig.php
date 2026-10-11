<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

use Grpc\ChannelCredentials;
use InvalidArgumentException;
use RuntimeException;

/**
 * Immutable transport policy for PHP gRPC clients.
 *
 * Plaintext is intentionally available only to an explicitly non-production
 * environment. Secure modes read PEM material from files and never place the
 * contents in environment variables, logs, or exception messages.
 */
final readonly class GrpcTlsConfig
{
    public const MODE_PLAINTEXT = 'plaintext';
    public const MODE_TLS = 'tls';
    public const MODE_MTLS = 'mtls';

    public function __construct(
        public string $mode,
        public ?string $caPem = null,
        public ?string $clientKeyPem = null,
        public ?string $clientCertPem = null,
        public ?string $serverName = null,
    ) {
    }

    /**
     * @param array<string, string|null> $environment Optional overrides used by contract tests.
     * @throws InvalidArgumentException|RuntimeException when secure configuration is incomplete or unreadable.
     */
    public static function fromEnvironment(?string $target = null, array $environment = []): self
    {
        $appEnv = strtolower(trim((string)self::value('APP_ENV', $environment, 'local')));
        $isProduction = in_array($appEnv, ['production', 'prod'], true);
        $mode = strtolower(trim((string)self::value(
            'GRPC_TLS_MODE',
            $environment,
            $isProduction ? '' : self::MODE_PLAINTEXT,
        )));

        if ($isProduction && $mode === '') {
            throw new InvalidArgumentException('GRPC_TLS_MODE is required when APP_ENV=production');
        }
        if (!in_array($mode, [self::MODE_PLAINTEXT, self::MODE_TLS, self::MODE_MTLS], true)) {
            throw new InvalidArgumentException(
                'GRPC_TLS_MODE must be plaintext, tls, or mtls; received ' . ($mode === '' ? '<empty>' : $mode)
            );
        }
        if ($isProduction && $mode === self::MODE_PLAINTEXT) {
            throw new InvalidArgumentException('GRPC_TLS_MODE=plaintext is forbidden when APP_ENV=production');
        }
        if ($mode === self::MODE_PLAINTEXT) {
            return new self(self::MODE_PLAINTEXT);
        }

        $caFile = self::requiredPath('GRPC_TLS_CA_FILE', $environment);
        $serverName = self::serverName($target, $environment);
        $caPem = self::readPemFile($caFile, 'GRPC_TLS_CA_FILE');

        if ($mode === self::MODE_TLS) {
            return new self($mode, $caPem, null, null, $serverName);
        }

        $keyFile = self::requiredPath('GRPC_TLS_KEY_FILE', $environment);
        $certFile = self::requiredPath('GRPC_TLS_CERT_FILE', $environment);

        return new self(
            $mode,
            $caPem,
            self::readPemFile($keyFile, 'GRPC_TLS_KEY_FILE'),
            self::readPemFile($certFile, 'GRPC_TLS_CERT_FILE'),
            $serverName,
        );
    }

    /**
     * Options accepted by Grpc\\BaseStub. Keeping credential construction in
     * this policy object prevents call sites from silently selecting insecure
     * transport.
     *
     * @return array<string, mixed>
     */
    public function stubOptions(): array
    {
        $options = [
            'credentials' => $this->mode === self::MODE_PLAINTEXT
                ? ChannelCredentials::createInsecure()
                : ChannelCredentials::createSsl($this->caPem, $this->clientKeyPem, $this->clientCertPem),
        ];

        if ($this->serverName !== null) {
            // The override is only used with TLS and preserves certificate
            // hostname verification for service targets such as host:port.
            $options['grpc.ssl_target_name_override'] = $this->serverName;
        }

        return $options;
    }

    private static function requiredPath(string $name, array $environment): string
    {
        $path = trim((string)self::value($name, $environment, ''));
        if ($path === '') {
            throw new InvalidArgumentException($name . ' is required for secure gRPC transport');
        }

        return $path;
    }

    private static function readPemFile(string $path, string $name): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException($name . ' must point to a readable PEM file');
        }
        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            throw new RuntimeException($name . ' points to an empty or unreadable PEM file');
        }

        return $contents;
    }

    private static function serverName(?string $target, array $environment): string
    {
        $serverName = trim((string)self::value('GRPC_TLS_SERVER_NAME', $environment, ''));
        if ($serverName === '' && $target !== null) {
            $targetHost = self::targetHost($target);
            if ($targetHost !== '') {
                $envName = 'GRPC_TLS_SERVER_NAME_' . strtoupper(trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', $targetHost), '_'));
                $serverName = trim((string)self::value($envName, $environment, ''));
            }
        }
        if ($serverName === '') {
            throw new InvalidArgumentException(
                'GRPC_TLS_SERVER_NAME or a target-specific GRPC_TLS_SERVER_NAME_* value is required'
            );
        }
        if (preg_match('/[\x00-\x20\x7f]/', $serverName) === 1) {
            throw new InvalidArgumentException('GRPC TLS server name must not contain control characters or whitespace');
        }

        return $serverName;
    }

    private static function targetHost(string $target): string
    {
        $authority = preg_replace('/^[A-Za-z][A-Za-z0-9+.-]*:\/\//', '', trim($target)) ?? trim($target);
        $authority = explode('/', $authority, 2)[0];
        if (str_starts_with($authority, '[')) {
            $end = strpos($authority, ']');
            return $end === false ? trim($authority, '[]') : substr($authority, 1, $end - 1);
        }

        return (string)preg_replace('/:\d+$/', '', $authority);
    }

    /** @param array<string, string|null> $environment */
    private static function value(string $name, array $environment, ?string $default): ?string
    {
        if (array_key_exists($name, $environment)) {
            return $environment[$name];
        }

        $value = getenv($name);

        return $value === false ? $default : $value;
    }
}
