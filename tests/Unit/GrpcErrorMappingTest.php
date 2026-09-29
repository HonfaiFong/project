<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Grpc\AuthServiceClient;
use Apps\ApiGateway\Grpc\UserServiceClient;
use Contracts\Errors\ErrorCode;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use const Grpc\STATUS_DEADLINE_EXCEEDED;
use const Grpc\STATUS_NOT_FOUND;
use const Grpc\STATUS_PERMISSION_DENIED;
use const Grpc\STATUS_UNAUTHENTICATED;

final class GrpcErrorMappingTest extends TestCase
{
    public function testGrpcCallExceptionHoldsProperties(): void
    {
        $exWithAppCode = new GrpcCallException(STATUS_UNAUTHENTICATED, ErrorCode::AUTH_TOKEN_EXPIRED->value, 'token expired');
        $this->assertSame(STATUS_UNAUTHENTICATED, $exWithAppCode->grpcStatus);
        $this->assertSame(ErrorCode::AUTH_TOKEN_EXPIRED->value, $exWithAppCode->appErrorCode);
        $this->assertSame(ErrorCode::AUTH_TOKEN_EXPIRED->value, $exWithAppCode->getCode());
        $this->assertSame('token expired', $exWithAppCode->getMessage());

        $exWithoutAppCode = new GrpcCallException(STATUS_NOT_FOUND, null, 'not found');
        $this->assertSame(STATUS_NOT_FOUND, $exWithoutAppCode->grpcStatus);
        $this->assertNull($exWithoutAppCode->appErrorCode);
        $this->assertSame(STATUS_NOT_FOUND, $exWithoutAppCode->getCode());
    }

    public function testUnaryGrpcClientHasNoBusinessErrorCoupling(): void
    {
        $ref = new ReflectionClass(UnaryGrpcClient::class);
        $source = (string)file_get_contents((string)$ref->getFileName());

        $this->assertStringNotContainsString('USER_NOT_FOUND', $source);
        $this->assertStringNotContainsString('PERSONA_FORBIDDEN', $source);
        $this->assertStringNotContainsString('AUTH_INVALID_CREDENTIALS', $source);
        $this->assertStringNotContainsString('Contracts\Errors\ErrorCode', $source);
    }

    public function testAuthServiceClientPreservesAppErrorCode(): void
    {
        $client = new AuthServiceClient('127.0.0.1:1', 300);
        $ref = new ReflectionMethod($client, 'mapGrpcCallException');

        $ex = new GrpcCallException(STATUS_UNAUTHENTICATED, ErrorCode::AUTH_TOKEN_EXPIRED->value, 'token expired');
        /** @var RuntimeException $mapped */
        $mapped = $ref->invoke($client, $ex);

        $this->assertSame(ErrorCode::AUTH_TOKEN_EXPIRED->value, $mapped->getCode());
        $this->assertSame('token expired', $mapped->getMessage());
    }

    public function testAuthServiceClientFallsBackToAuthDomainForRawGrpcStatus(): void
    {
        $client = new AuthServiceClient('127.0.0.1:1', 300);
        $ref = new ReflectionMethod($client, 'mapGrpcCallException');

        $ex = new GrpcCallException(STATUS_UNAUTHENTICATED, null, 'unauthenticated');
        /** @var RuntimeException $mapped */
        $mapped = $ref->invoke($client, $ex);

        $this->assertSame(ErrorCode::AUTH_UNAUTHORIZED->value, $mapped->getCode());

        $timeoutEx = new GrpcCallException(STATUS_DEADLINE_EXCEEDED, null, 'timeout');
        /** @var RuntimeException $mappedTimeout */
        $mappedTimeout = $ref->invoke($client, $timeoutEx);

        $this->assertSame(ErrorCode::RPC_TIMEOUT->value, $mappedTimeout->getCode());
    }

    public function testUserServiceClientPreservesAppErrorCode(): void
    {
        $client = new UserServiceClient('127.0.0.1:1', 150);
        $ref = new ReflectionMethod($client, 'mapGrpcCallException');

        $ex = new GrpcCallException(STATUS_PERMISSION_DENIED, ErrorCode::PERSONA_LIMIT_EXCEEDED->value, 'limit exceeded');
        /** @var RuntimeException $mapped */
        $mapped = $ref->invoke($client, $ex);

        $this->assertSame(ErrorCode::PERSONA_LIMIT_EXCEEDED->value, $mapped->getCode());
        $this->assertSame('limit exceeded', $mapped->getMessage());
    }

    public function testUserServiceClientFallsBackToUserDomainForRawGrpcStatus(): void
    {
        $client = new UserServiceClient('127.0.0.1:1', 150);
        $ref = new ReflectionMethod($client, 'mapGrpcCallException');

        $notFoundEx = new GrpcCallException(STATUS_NOT_FOUND, null, 'user not found');
        /** @var RuntimeException $mappedNotFound */
        $mappedNotFound = $ref->invoke($client, $notFoundEx);

        $this->assertSame(ErrorCode::USER_NOT_FOUND->value, $mappedNotFound->getCode());

        $permEx = new GrpcCallException(STATUS_PERMISSION_DENIED, null, 'forbidden');
        /** @var RuntimeException $mappedPerm */
        $mappedPerm = $ref->invoke($client, $permEx);

        $this->assertSame(ErrorCode::PERSONA_FORBIDDEN->value, $mappedPerm->getCode());
    }
}
