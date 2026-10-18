<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * gRPC 截止时间配置的部署级协议约定。
 *
 * 运行时检查位于网关的 AuthServiceClient 和认证服务的 GrpcUserDirectoryClient，但跨服务预算关系涉及两个部署：
 * auth->user 调用预算须小于网关外层认证截止时间，确保客户端观察到外层截止时间。此关系只能在两处配置汇合的本仓库
 * 中检查。
 */
final class GrpcDeadlineConfigContractTest extends TestCase
{
    private const GATEWAY_DEADLINE_SETTINGS = [
        'GRPC_AUTH_TIMEOUT_MS',
        'GRPC_SOCIAL_LOGIN_TIMEOUT_MS',
        'GRPC_SOCIAL_BIND_TIMEOUT_MS',
        'SOCIAL_HTTP_TIMEOUT_MS',
    ];

    public function testComposeDefaultsAndEnvExampleAgreeOnEveryDeadline(): void
    {
        $compose = $this->composeDeadlineDefaults();
        $envExample = $this->envExampleSettings();

        foreach (array_unique([...self::GATEWAY_DEADLINE_SETTINGS, 'GRPC_USER_DIRECTORY_TIMEOUT_MS']) as $name) {
            $this->assertArrayHasKey($name, $compose, $name . ' must keep an explicit compose.yaml default');
            $this->assertArrayHasKey($name, $envExample, $name . ' must be documented in .env.example');
            $this->assertSame(
                $compose[$name],
                $envExample[$name],
                $name . ' default disagrees between compose.yaml and .env.example'
            );
            $this->assertGreaterThan(0, $compose[$name], $name . ' must be a positive deadline');
        }
    }

    public function testUserDirectoryHopStaysBelowTheGatewayAuthDeadline(): void
    {
        $settings = $this->envExampleSettings();

        $this->assertLessThan(
            $settings['GRPC_AUTH_TIMEOUT_MS'],
            $settings['GRPC_USER_DIRECTORY_TIMEOUT_MS'],
            'GRPC_USER_DIRECTORY_TIMEOUT_MS must stay below GRPC_AUTH_TIMEOUT_MS so the outer gateway deadline rules'
        );
    }

    public function testSocialRpcDeadlinesCoverTheWorstCaseOauthHttpSequence(): void
    {
        $settings = $this->envExampleSettings();
        $twoOauthExchanges = 2 * $settings['SOCIAL_HTTP_TIMEOUT_MS'];

        $this->assertGreaterThan(
            $twoOauthExchanges,
            $settings['GRPC_SOCIAL_LOGIN_TIMEOUT_MS'],
            'GRPC_SOCIAL_LOGIN_TIMEOUT_MS must exceed two SOCIAL_HTTP_TIMEOUT_MS exchanges'
        );
        $this->assertGreaterThan(
            $twoOauthExchanges,
            $settings['GRPC_SOCIAL_BIND_TIMEOUT_MS'],
            'GRPC_SOCIAL_BIND_TIMEOUT_MS must exceed two SOCIAL_HTTP_TIMEOUT_MS exchanges'
        );
    }

    public function testNonPositiveDeadlineIsRejectedAtGatewayConfigLoad(): void
    {
        $previous = getenv('GRPC_AUTH_TIMEOUT_MS');
        putenv('GRPC_AUTH_TIMEOUT_MS=0');

        try {
            $this->expectException(InvalidArgumentException::class);
            include dirname(__DIR__, 2) . '/apps/api-gateway/config/gateway.php';
        } finally {
            $previous === false ? putenv('GRPC_AUTH_TIMEOUT_MS') : putenv('GRPC_AUTH_TIMEOUT_MS=' . $previous);
        }
    }

    public function testNonNumericDeadlineIsRejectedAtGatewayConfigLoad(): void
    {
        $previous = getenv('GRPC_SOCIAL_LOGIN_TIMEOUT_MS');
        putenv('GRPC_SOCIAL_LOGIN_TIMEOUT_MS=7s');

        try {
            $this->expectException(InvalidArgumentException::class);
            include dirname(__DIR__, 2) . '/apps/api-gateway/config/gateway.php';
        } finally {
            $previous === false ? putenv('GRPC_SOCIAL_LOGIN_TIMEOUT_MS') : putenv('GRPC_SOCIAL_LOGIN_TIMEOUT_MS=' . $previous);
        }
    }

    public function testUserDirectoryClientRejectsNonPositiveDeadline(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new \Services\AuthService\Infrastructure\Client\GrpcUserDirectoryClient(timeoutMs: 0);
    }

    /**
     * 认证服务启动流程须拒绝与网关相同的无效值；此前 bin/server.php 的“(int)”转换在构造器检查前，已将 'abc' 截断为 1
     * 毫秒，将 '-100' 转换为看似有效的正截止时间。
     */
    #[DataProvider('invalidDeadlineValues')]
    public function testAuthServiceRejectsInvalidUserDirectoryDeadlineValues(string $value): void
    {
        $this->withEnvironment('GRPC_USER_DIRECTORY_TIMEOUT_MS', $value, function (): void {
            $this->expectException(InvalidArgumentException::class);

            \Services\AuthService\Infrastructure\Config\GrpcDeadlineConfig::fromEnvironment();
        });
    }

    /** @return list<array{string}> */
    public static function invalidDeadlineValues(): array
    {
        return [
            ['0'],
            ['-100'],
            ['abc'],
            ['250ms'],
            ['7.5'],
        ];
    }

    public function testAuthServiceRejectsADirectoryBudgetThatEatsTheGatewayDeadline(): void
    {
        $this->withEnvironment('GRPC_AUTH_TIMEOUT_MS', '300', function (): void {
            $this->withEnvironment('GRPC_USER_DIRECTORY_TIMEOUT_MS', '500', function (): void {
                $this->expectException(InvalidArgumentException::class);
                $this->expectExceptionMessageMatches('/must stay below/');

                \Services\AuthService\Infrastructure\Config\GrpcDeadlineConfig::fromEnvironment();
            });
        });
    }

    public function testAuthServiceResolvesAValidDeployedPair(): void
    {
        $this->withEnvironment('GRPC_AUTH_TIMEOUT_MS', '300', function (): void {
            $this->withEnvironment('GRPC_USER_DIRECTORY_TIMEOUT_MS', '250', function (): void {
                $config = \Services\AuthService\Infrastructure\Config\GrpcDeadlineConfig::fromEnvironment();

                $this->assertSame(250, $config->userDirectoryTimeoutMs);
                $this->assertSame(300, $config->gatewayAuthTimeoutMs);
            });
        });
    }

    /**
     * compose.yaml 须持续向 auth-service 容器注入网关截止时间，否则启动时的交叉检查会静默失去对已部署配置组合的可见
     * 性。
     */
    public function testComposePassesTheGatewayAuthDeadlineToAuthService(): void
    {
        $compose = (string)file_get_contents(dirname(__DIR__, 2) . '/compose.yaml');
        $authServiceSection = substr($compose, (int)strpos($compose, '  auth-service:'));
        $authServiceSection = substr($authServiceSection, 0, (int)strpos($authServiceSection, '  user-service:'));

        $this->assertMatchesRegularExpression(
            '/GRPC_AUTH_TIMEOUT_MS=\$\{GRPC_AUTH_TIMEOUT_MS:-300\}/',
            $authServiceSection,
            'auth-service environment must carry the gateway GRPC_AUTH_TIMEOUT_MS default'
        );
    }

    private function withEnvironment(string $name, string $value, callable $body): void
    {
        $previous = getenv($name);
        putenv($name . '=' . $value);

        try {
            $body();
        } finally {
            $previous === false ? putenv($name) : putenv($name . '=' . $previous);
        }
    }

    /** @return array<string, int> */
    private function composeDeadlineDefaults(): array
    {
        $compose = (string)file_get_contents(dirname(__DIR__, 2) . '/compose.yaml');
        $settings = [];
        foreach (array_unique([...self::GATEWAY_DEADLINE_SETTINGS, 'GRPC_USER_DIRECTORY_TIMEOUT_MS']) as $name) {
            if (!preg_match('/' . $name . '=\$\{' . $name . ':-(\d+)\}/', $compose, $matches)) {
                throw new RuntimeException($name . ' not found with an explicit default in compose.yaml');
            }
            $settings[$name] = (int)$matches[1];
        }

        return $settings;
    }

    /** @return array<string, int> */
    private function envExampleSettings(): array
    {
        $envExample = (string)file_get_contents(dirname(__DIR__, 2) . '/.env.example');
        $settings = [];
        foreach (array_unique([...self::GATEWAY_DEADLINE_SETTINGS, 'GRPC_USER_DIRECTORY_TIMEOUT_MS']) as $name) {
            if (!preg_match('/^' . $name . '=(\d+)\r?$/m', $envExample, $matches)) {
                throw new RuntimeException($name . ' not found in .env.example');
            }
            $settings[$name] = (int)$matches[1];
        }

        return $settings;
    }
}
