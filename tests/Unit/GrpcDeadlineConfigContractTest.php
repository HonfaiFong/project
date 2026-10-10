<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Deployment-level contract for the gRPC deadline knobs.
 *
 * The runtime guards live in AuthServiceClient (gateway) and
 * GrpcUserDirectoryClient (auth-service), but the cross-service relationship —
 * the auth->user hop must stay below the gateway's outer auth deadline so the
 * outer deadline is what clients observe — spans two deployments and can only
 * be checked where both files meet: this repository.
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
            if (!preg_match('/^' . $name . '=(\d+)$/m', $envExample, $matches)) {
                throw new RuntimeException($name . ' not found in .env.example');
            }
            $settings[$name] = (int)$matches[1];
        }

        return $settings;
    }
}
