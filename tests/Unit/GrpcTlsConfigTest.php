<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Packages\GrpcCommon\GrpcTlsConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GrpcTlsConfigTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->temporaryFiles = [];
    }

    public function testProductionRejectsPlaintextTransport(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GrpcTlsConfig::fromEnvironment(environment: [
            'APP_ENV' => 'production',
            'GRPC_TLS_MODE' => 'plaintext',
        ]);
    }

    public function testProductionRequiresAnExplicitSecureMode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GrpcTlsConfig::fromEnvironment(environment: [
            'APP_ENV' => 'production',
            'GRPC_TLS_MODE' => '',
        ]);
    }

    public function testLocalPlaintextIsAvailableOnlyWhenSelected(): void
    {
        $config = GrpcTlsConfig::fromEnvironment(environment: [
            'APP_ENV' => 'local',
            'GRPC_TLS_MODE' => 'plaintext',
        ]);

        $this->assertSame(GrpcTlsConfig::MODE_PLAINTEXT, $config->mode);
        $this->assertNull($config->caPem);
        $this->assertNull($config->serverName);
    }

    public function testTlsLoadsCaAndTargetSpecificServerNameFromFilesAndEnvironment(): void
    {
        $ca = $this->pemFile("-----BEGIN CERTIFICATE-----\nca\n-----END CERTIFICATE-----\n");

        $config = GrpcTlsConfig::fromEnvironment('user-service:50052', [
            'APP_ENV' => 'production',
            'GRPC_TLS_MODE' => 'tls',
            'GRPC_TLS_CA_FILE' => $ca,
            'GRPC_TLS_SERVER_NAME_USER_SERVICE' => 'user-service.internal',
        ]);

        $this->assertSame(GrpcTlsConfig::MODE_TLS, $config->mode);
        $this->assertSame('user-service.internal', $config->serverName);
        $this->assertStringContainsString('BEGIN CERTIFICATE', (string)$config->caPem);
        $this->assertNull($config->clientKeyPem);
        $this->assertNull($config->clientCertPem);
    }

    public function testMtlsLoadsClientCertificateAndKey(): void
    {
        $ca = $this->pemFile('ca');
        $key = $this->pemFile('private-key');
        $cert = $this->pemFile('client-cert');

        $config = GrpcTlsConfig::fromEnvironment(environment: [
            'APP_ENV' => 'production',
            'GRPC_TLS_MODE' => 'mtls',
            'GRPC_TLS_CA_FILE' => $ca,
            'GRPC_TLS_KEY_FILE' => $key,
            'GRPC_TLS_CERT_FILE' => $cert,
            'GRPC_TLS_SERVER_NAME' => 'auth-service.internal',
        ]);

        $this->assertSame(GrpcTlsConfig::MODE_MTLS, $config->mode);
        $this->assertSame('private-key', $config->clientKeyPem);
        $this->assertSame('client-cert', $config->clientCertPem);
    }

    public function testMissingCertificatePathFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);

        GrpcTlsConfig::fromEnvironment(environment: [
            'APP_ENV' => 'production',
            'GRPC_TLS_MODE' => 'tls',
            'GRPC_TLS_CA_FILE' => $this->temporaryFilePath('does-not-exist'),
            'GRPC_TLS_SERVER_NAME' => 'auth-service.internal',
        ]);
    }

    public function testSecureModeRequiresServerName(): void
    {
        $ca = $this->pemFile('ca');

        $this->expectException(InvalidArgumentException::class);

        GrpcTlsConfig::fromEnvironment(environment: [
            'APP_ENV' => 'production',
            'GRPC_TLS_MODE' => 'tls',
            'GRPC_TLS_CA_FILE' => $ca,
        ]);
    }

    private function pemFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'grpc-tls-');
        self::assertNotFalse($path);
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function temporaryFilePath(string $suffix): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'grpc-tls-' . $suffix . '-' . bin2hex(random_bytes(4));
    }
}
