<?php

declare(strict_types=1);

namespace Tests\Unit;

use Packages\GrpcCommon\GrpcClientContext;
use Tests\TestCase;

final class GrpcClientContextTest extends TestCase
{
    private string|false $originalServiceName;

    protected function setUp(): void
    {
        $this->originalServiceName = getenv('SERVICE_NAME');
        putenv('SERVICE_NAME=api-gateway');
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment('SERVICE_NAME', $this->originalServiceName);
    }

    public function testServiceIdentityIsInjectedAndCannotBeOverriddenByRpcMetadata(): void
    {
        $options = GrpcClientContext::createCallOptions(300, [
            'x-service-name'  => 'untrusted-service',
            'x-service-token' => 'untrusted-token',
            'X-Service-Name'  => 'untrusted-uppercase-service',
        ], 'internal-rpc-token-for-unit-tests-32');

        $this->assertSame(['api-gateway'], $options['metadata']['x-service-name']);
        $this->assertArrayNotHasKey('X-Service-Name', $options['metadata']);
        $this->assertSame(
            ['internal-rpc-token-for-unit-tests-32'],
            $options['metadata']['x-service-token'],
        );
        $this->assertSame(300000, $options['timeout']);
    }

    private function restoreEnvironment(string $key, string|false $value): void
    {
        if ($value === false) {
            putenv($key);
            return;
        }

        putenv($key . '=' . $value);
    }
}
