<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TransportSecurityContractTest extends TestCase
{
    public function testUnaryClientDelegatesCredentialsToTheTlsPolicy(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/packages/php/grpc-common/src/UnaryGrpcClient.php');

        $this->assertStringContainsString('GrpcTlsConfig::fromEnvironment($target)->stubOptions()', $source);
        $this->assertStringNotContainsString('ChannelCredentials::createInsecure()', $source);
    }

    public function testRoadRunnerEntrypointFailsClosedAndConfiguresTlsOverrides(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/scripts/roadrunner-entrypoint.sh');

        $this->assertStringContainsString('plaintext gRPC is forbidden', $source);
        $this->assertStringContainsString('GRPC_TLS_CA_FILE', $source);
        $this->assertStringContainsString('grpc.tls.key=', $source);
        $this->assertStringContainsString('grpc.tls.cert=', $source);
        $this->assertStringContainsString('grpc.tls.root_ca=', $source);
        $this->assertStringContainsString('require_and_verify_client_cert', $source);
    }

    /** @dataProvider roadrunnerConfigProvider */
    public function testEveryRoadRunnerGrpcServiceUsesTheSharedTlsEntrypoint(string $service): void
    {
        $compose = (string)file_get_contents(dirname(__DIR__, 2) . '/compose.yaml');
        $this->assertStringContainsString(
            'command: sh /app/scripts/roadrunner-entrypoint.sh /app/infra/roadrunner/' . $service . '.yaml',
            $compose,
        );
        $this->assertFileExists(dirname(__DIR__, 2) . '/infra/roadrunner/' . $service . '.yaml');
    }

    public function testRustFsHostPublicationDefaultsToLoopbackAndIsOverridable(): void
    {
        $compose = (string)file_get_contents(dirname(__DIR__, 2) . '/compose.yaml');

        $this->assertStringContainsString('${RUSTFS_BIND_ADDRESS:-127.0.0.1}:9000:9000', $compose);
        $this->assertStringContainsString('${RUSTFS_BIND_ADDRESS:-127.0.0.1}:9001:9001', $compose);
    }

    /** @return array<string, array{string}> */
    public static function roadrunnerConfigProvider(): array
    {
        return [
            'auth' => ['auth'],
            'user' => ['user'],
            'content' => ['content'],
            'social' => ['social'],
            'chat' => ['chat'],
            'message' => ['message'],
        ];
    }
}
