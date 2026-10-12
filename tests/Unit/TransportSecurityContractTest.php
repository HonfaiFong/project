<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Packages\GrpcCommon\GrpcClientContext;

final class TransportSecurityContractTest extends TestCase
{
    public function testMessageConsumerUsesChatRpcIdentityAndSeparateTelemetryName(): void
    {
        $compose = (string)file_get_contents(dirname(__DIR__, 2) . '/compose.yaml');
        $start = strpos($compose, "  chat-message-consumer:\n");
        $end = strpos($compose, "  api-gateway:\n", (int)$start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $consumer = substr($compose, $start, $end - $start);
        $this->assertStringContainsString('- SERVICE_NAME=chat-service', $consumer);
        $this->assertStringContainsString('- OTEL_SERVICE_NAME=chat-message-consumer', $consumer);
        $this->assertStringContainsString('INTERNAL_RPC_CHAT_USER_TOKEN', $consumer);
        $this->assertStringContainsString('INTERNAL_RPC_CHAT_SOCIAL_TOKEN', $consumer);

        $previous = getenv('SERVICE_NAME');
        putenv('SERVICE_NAME=chat-service');
        try {
            $options = GrpcClientContext::createCallOptions(200, ['x-service-name' => 'chat-message-consumer'], 'test-chat-token');
            $this->assertSame(['chat-service'], $options['metadata']['x-service-name']);
            $this->assertSame(['test-chat-token'], $options['metadata']['x-service-token']);
        } finally {
            putenv($previous === false ? 'SERVICE_NAME' : 'SERVICE_NAME=' . $previous);
        }
    }

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

    #[DataProvider('roadrunnerConfigProvider')]
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
