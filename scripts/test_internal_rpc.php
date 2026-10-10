<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Contracts\Gen\Auth\V1\BindSocialIdentityRequest;
use Contracts\Gen\Auth\V1\BindSocialIdentityResponse;
use Contracts\Gen\Auth\V1\BindSocialPhoneRequest;
use Contracts\Gen\Auth\V1\BindSocialPhoneResponse;
use Contracts\Gen\Auth\V1\SendSocialPhoneCodeRequest;
use Contracts\Gen\Auth\V1\SendSocialPhoneCodeResponse;
use Contracts\Gen\Auth\V1\SocialProvider;
use Contracts\Gen\Common\V1\HealthCheckRequest;
use Contracts\Gen\Common\V1\HealthCheckResponse;
use Contracts\Gen\User\V1\FindOrCreateByPhoneRequest;
use Contracts\Gen\User\V1\FindOrCreateByPhoneResponse;
use Contracts\Gen\User\V1\FindOrCreateBySocialIdentityRequest;
use Contracts\Gen\User\V1\FindOrCreateBySocialIdentityResponse;
use Contracts\Gen\User\V1\GetUserRequest;
use Contracts\Gen\User\V1\GetUserResponse;
use Contracts\Gen\User\V1\UpdateUserRequest;
use Contracts\Gen\User\V1\UpdateUserResponse;
use Contracts\Errors\ErrorCode;
use Google\Protobuf\Internal\Message;
use Packages\GrpcCommon\GrpcCallException;
use Packages\GrpcCommon\UnaryGrpcClient;
use Spiral\RoadRunner\GRPC\StatusCode;

final class InternalRpcSmokeRunner
{
    private const WRONG_TOKEN = 'internal-rpc-smoke-invalid-token-0000';

    public function run(string $caller): void
    {
        $expectedServiceName = match ($caller) {
            'api-gateway', 'auth-service' => $caller,
            default => throw new RuntimeException('Unsupported internal RPC smoke caller'),
        };
        if (trim((string)(getenv('SERVICE_NAME') ?: '')) !== $expectedServiceName) {
            throw new RuntimeException('Internal RPC smoke caller does not match SERVICE_NAME');
        }

        $target = getenv('GRPC_USER_SERVICE') ?: 'user-service:50052';
        $validTokenEnv = $caller === 'api-gateway' ? 'GRPC_USER_TOKEN' : 'GRPC_USER_SERVICE_TOKEN';
        $validToken = trim((string)(getenv($validTokenEnv) ?: ''));
        if ($validToken === '') {
            throw new RuntimeException('Internal RPC smoke token environment is missing');
        }

        if ($caller === 'api-gateway') {
            $this->runGatewayChecks($target, $validToken);
            return;
        }

        $this->runAuthServiceChecks($target, $validToken);
    }

    private function runGatewayChecks(string $target, string $validToken): void
    {
        $authTarget = getenv('GRPC_AUTH_SERVICE') ?: 'auth-service:50051';
        $authToken = trim((string)(getenv('GRPC_AUTH_TOKEN') ?: ''));
        if ($authToken === '') {
            throw new RuntimeException('Gateway auth-service RPC token environment is missing');
        }

        foreach ([null, self::WRONG_TOKEN] as $token) {
            $this->expectStatus(
                $token === null ? 'auth missing token' : 'auth wrong token',
                new UnaryGrpcClient($authTarget, $token),
                '/common.v1.HealthService/Check',
                new HealthCheckRequest(),
                HealthCheckResponse::class,
                StatusCode::UNAUTHENTICATED,
            );
        }

        $request = new GetUserRequest(['user_id' => '0']);
        $this->expectStatus(
            'gateway missing token',
            new UnaryGrpcClient($target),
            '/user.v1.UserService/GetUser',
            $request,
            GetUserResponse::class,
            StatusCode::UNAUTHENTICATED,
        );
        $this->expectStatus(
            'gateway wrong token',
            new UnaryGrpcClient($target, self::WRONG_TOKEN),
            '/user.v1.UserService/GetUser',
            $request,
            GetUserResponse::class,
            StatusCode::UNAUTHENTICATED,
        );
        $this->expectStatus(
            'gateway valid token',
            new UnaryGrpcClient($target, $validToken),
            '/user.v1.UserService/GetUser',
            $request,
            GetUserResponse::class,
            StatusCode::INVALID_ARGUMENT,
        );

        $this->expectStatus(
            'gateway social registration invalid argument',
            new UnaryGrpcClient($target, $validToken),
            '/user.v1.UserService/FindOrCreateBySocialIdentity',
            new FindOrCreateBySocialIdentityRequest(),
            FindOrCreateBySocialIdentityResponse::class,
            StatusCode::INVALID_ARGUMENT,
        );
        $this->expectStatus(
            'gateway social bind requires an access token',
            new UnaryGrpcClient($authTarget, $authToken),
            '/auth.v1.AuthService/BindSocialIdentity',
            new BindSocialIdentityRequest([
                'provider'     => SocialProvider::SOCIAL_PROVIDER_WECHAT,
                'code'         => '',
                'access_token' => '',
            ]),
            BindSocialIdentityResponse::class,
            StatusCode::UNAUTHENTICATED,
        );

        // 废弃的短信手机号绑定 RPC 为保持协议兼容而保留声明，但不能再处理任何调用方请求。
        $this->expectRejected(
            'gateway legacy SendSocialPhoneCode rejected',
            new UnaryGrpcClient($authTarget, $authToken),
            '/auth.v1.AuthService/SendSocialPhoneCode',
            new SendSocialPhoneCodeRequest(['bind_token' => 'internal-rpc-smoke', 'phone' => '+8613800000000']),
            SendSocialPhoneCodeResponse::class,
        );
        $this->expectRejected(
            'gateway legacy BindSocialPhone rejected',
            new UnaryGrpcClient($authTarget, $authToken),
            '/auth.v1.AuthService/BindSocialPhone',
            new BindSocialPhoneRequest([
                'bind_token' => 'internal-rpc-smoke',
                'phone'      => '+8613800000000',
                'code'       => '123456',
            ]),
            BindSocialPhoneResponse::class,
        );
    }

    private function runAuthServiceChecks(string $target, string $validToken): void
    {
        $this->expectStatus(
            'auth-service UpdateUser denied',
            new UnaryGrpcClient($target, $validToken),
            '/user.v1.UserService/UpdateUser',
            new UpdateUserRequest([
                'user_id' => '1',
                'email'   => 'internal-rpc-smoke-must-not-write@example.invalid',
            ]),
            UpdateUserResponse::class,
            StatusCode::PERMISSION_DENIED,
        );
        $this->expectStatus(
            'auth-service GetUser allowed',
            new UnaryGrpcClient($target, $validToken),
            '/user.v1.UserService/GetUser',
            new GetUserRequest(['user_id' => '0']),
            GetUserResponse::class,
            StatusCode::INVALID_ARGUMENT,
        );
        $this->expectStatus(
            'auth-service FindOrCreateByPhone allowed',
            new UnaryGrpcClient($target, $validToken),
            '/user.v1.UserService/FindOrCreateByPhone',
            new FindOrCreateByPhoneRequest(['phone' => '']),
            FindOrCreateByPhoneResponse::class,
            StatusCode::INVALID_ARGUMENT,
        );
        $this->expectStatus(
            'auth-service FindOrCreateBySocialIdentity allowed',
            new UnaryGrpcClient($target, $validToken),
            '/user.v1.UserService/FindOrCreateBySocialIdentity',
            new FindOrCreateBySocialIdentityRequest(['registration_key' => '']),
            FindOrCreateBySocialIdentityResponse::class,
            StatusCode::INVALID_ARGUMENT,
        );
        $this->expectStatus(
            'auth-service health denied',
            new UnaryGrpcClient($target, $validToken),
            '/common.v1.HealthService/Check',
            new HealthCheckRequest(),
            HealthCheckResponse::class,
            StatusCode::PERMISSION_DENIED,
        );
    }

    /**
     * @param class-string<Message> $responseClass
     */
    private function expectStatus(
        string $case,
        UnaryGrpcClient $client,
        string $method,
        Message $request,
        string $responseClass,
        int $expectedStatus,
    ): void {
        try {
            $client->call($method, $request, $responseClass, 1000);
        } catch (GrpcCallException $e) {
            if ($e->grpcStatus !== $expectedStatus) {
                throw new RuntimeException(
                    sprintf('%s returned unexpected gRPC status %d', $case, $e->grpcStatus),
                );
            }

            printf("PASS case=%s status=%d\n", $case, $expectedStatus);
            return;
        }

        throw new RuntimeException(sprintf('%s unexpectedly succeeded', $case));
    }

    /**
     * 废弃 RPC 须因授权原因拒绝调用；截止时间已过、后端不可用或其他传输故障不能证明 RPC 已关闭，因此检查必须失败。
     *
     * @param class-string<Message> $responseClass
     */
    private function expectRejected(
        string $case,
        UnaryGrpcClient $client,
        string $method,
        Message $request,
        string $responseClass,
    ): void {
        try {
            $client->call($method, $request, $responseClass, 1000);
        } catch (GrpcCallException $e) {
            $rejected = in_array($e->grpcStatus, [
                StatusCode::UNAUTHENTICATED,
                StatusCode::PERMISSION_DENIED,
                StatusCode::UNIMPLEMENTED,
            ], true);
            $codeMatches = $e->appErrorCode === null
                || $e->appErrorCode === ErrorCode::AUTH_UNAUTHORIZED->value;
            if (!$rejected || !$codeMatches) {
                throw new RuntimeException(
                    sprintf('%s failed for an unrelated reason (status %d)', $case, $e->grpcStatus),
                );
            }

            printf("PASS case=%s status=%d\n", $case, $e->grpcStatus);
            return;
        }

        throw new RuntimeException(sprintf('%s unexpectedly succeeded', $case));
    }
}

$caller = 'api-gateway';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--caller=')) {
        $caller = substr($argument, strlen('--caller='));
        continue;
    }

    throw new RuntimeException('Unsupported internal RPC smoke argument');
}

try {
    (new InternalRpcSmokeRunner())->run($caller);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("FAIL internal-rpc-smoke exception=%s code=%d\n", $e::class, $e->getCode()));
    exit(1);
}
