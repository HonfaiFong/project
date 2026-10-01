<?php

declare(strict_types=1);

namespace Tests\Unit;

use Packages\GrpcCommon\InternalRpcAuthorizer;
use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\GRPC\Context;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\StatusCode;

final class InternalRpcAuthorizerTest extends TestCase
{
    private const TOKEN = 'internal-rpc-token-for-unit-tests-32';

    public function testAllowsConfiguredInternalCaller(): void
    {
        $authorizer = new InternalRpcAuthorizer(['api-gateway' => self::TOKEN]);

        $authorizer->authorize(new Context([
            'metadata' => [
                'x-service-name'  => ['api-gateway'],
                'x-service-token' => [self::TOKEN],
            ],
        ]));

        $this->addToAssertionCount(1);
    }

    public function testRejectsMissingOrInvalidCredentials(): void
    {
        $authorizer = new InternalRpcAuthorizer(['api-gateway' => self::TOKEN]);

        foreach ([
            [],
            ['x-service-name' => ['unknown-service'], 'x-service-token' => [self::TOKEN]],
            ['x-service-name' => ['api-gateway'], 'x-service-token' => ['wrong-token']],
        ] as $metadata) {
            try {
                $authorizer->authorize(new Context(['metadata' => $metadata]));
                $this->fail('Expected internal RPC authorization to fail');
            } catch (GRPCException $e) {
                $this->assertSame(StatusCode::UNAUTHENTICATED, $e->getCode());
            }
        }
    }

    public function testRejectsShortConfiguredToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new InternalRpcAuthorizer(['api-gateway' => 'too-short']);
    }

    public function testCallerMethodAllowListRejectsUnexpectedUserWrite(): void
    {
        $authorizer = new InternalRpcAuthorizer(
            callerTokens: ['auth-service' => self::TOKEN],
            allowedMethodsByCaller: ['auth-service' => ['FindOrCreateByPhone', 'GetUser']],
        );
        $context = new Context([
            'metadata' => [
                'x-service-name'  => ['auth-service'],
                'x-service-token' => [self::TOKEN],
            ],
        ]);

        $authorizer->authorize($context, 'GetUser');

        try {
            $authorizer->authorize($context, 'UpdateUser');
            $this->fail('Expected method authorization to fail');
        } catch (GRPCException $e) {
            $this->assertSame(StatusCode::PERMISSION_DENIED, $e->getCode());
        }
    }
}
