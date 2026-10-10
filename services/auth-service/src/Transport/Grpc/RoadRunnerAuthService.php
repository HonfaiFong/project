<?php

declare(strict_types=1);

namespace Services\AuthService\Transport\Grpc;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\Auth\V1\AuthServiceInterface;
use Contracts\Gen\Auth\V1\BindSocialIdentityRequest;
use Contracts\Gen\Auth\V1\BindSocialIdentityResponse;
use Contracts\Gen\Auth\V1\CreateWsTicketRequest;
use Contracts\Gen\Auth\V1\CreateWsTicketResponse;
use Contracts\Gen\Auth\V1\DeviceInfo;
use Contracts\Gen\Auth\V1\LoginRequest;
use Contracts\Gen\Auth\V1\LoginResponse;
use Contracts\Gen\Auth\V1\BindSocialPhoneRequest;
use Contracts\Gen\Auth\V1\BindSocialPhoneResponse;
use Contracts\Gen\Auth\V1\SendSocialPhoneCodeRequest;
use Contracts\Gen\Auth\V1\SendSocialPhoneCodeResponse;
use Contracts\Gen\Auth\V1\SocialLoginRequest;
use Contracts\Gen\Auth\V1\SocialLoginResponse;
use Contracts\Gen\Auth\V1\SocialLoginState;
use Contracts\Gen\Auth\V1\LogoutRequest;
use Contracts\Gen\Auth\V1\LogoutResponse;
use Contracts\Gen\Auth\V1\RefreshTokenRequest;
use Contracts\Gen\Auth\V1\RefreshTokenResponse;
use Contracts\Gen\Auth\V1\VerifyTokenRequest;
use Contracts\Gen\Auth\V1\VerifyTokenResponse;
use Packages\GrpcCommon\DeadlineContext;
use Packages\GrpcCommon\ErrorStatusMapper;
use Packages\GrpcCommon\InternalRpcAuthorizer;
use Psr\Log\LoggerInterface;
use Packages\Tracing\TraceContext;
use Random\RandomException;
use Services\AuthService\Application\Service\AuthApplicationService;
use Services\AuthService\Domain\Auth\SocialProvider;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\ResponseTrailers;
use Spiral\RoadRunner\GRPC\StatusCode;
use RuntimeException;
use Throwable;

final readonly class RoadRunnerAuthService implements AuthServiceInterface
{
    public function __construct(
        private AuthApplicationService $authService,
        private LoggerInterface $logger,
        private ?InternalRpcAuthorizer $authorizer = null,
    ) {
    }

    /**
     * @throws RandomException 共享追踪初始化无法生成 UUIDv7 时抛出
     * @throws GRPCException
     */
    public function Login(ContextInterface $ctx, LoginRequest $in): LoginResponse
    {
        return $this->call($ctx, 'Login', function () use ($in): LoginResponse {
            $phone = trim($in->getPhone());
            $code = trim($in->getCode());
            $password = $in->getPassword();
            $device = $this->deviceInfo($in->getDevice());

            $result = $this->authService->login(
                $phone,
                $code,
                $device,
                $password !== '' ? $password : null
            );

            return new LoginResponse([
                'user_id'                  => $this->uint64($result['user_id'], 'user_id'),
                'access_token'             => $result['access_token'],
                'refresh_token'            => $result['refresh_token'],
                'access_token_expires_at'  => (string)$result['access_token_expires_at'],
                'refresh_token_expires_at' => (string)$result['refresh_token_expires_at'],
                'is_new_user'              => $result['is_new_user'],
            ]);
        });
    }

    /**
     * @throws RandomException 共享追踪初始化无法生成 UUIDv7 时抛出
     * @throws GRPCException
     */
    public function RefreshToken(ContextInterface $ctx, RefreshTokenRequest $in): RefreshTokenResponse
    {
        return $this->call($ctx, 'RefreshToken', function () use ($in): RefreshTokenResponse {
            $refreshToken = $in->getRefreshToken();
            $device = $this->deviceInfo($in->getDevice());

            $result = $this->authService->refreshToken($refreshToken, $device);

            return new RefreshTokenResponse([
                'access_token'             => (string)$result['access_token'],
                'refresh_token'            => (string)$result['refresh_token'],
                'access_token_expires_at'  => (string)$result['access_token_expires_at'],
                'refresh_token_expires_at' => (string)$result['refresh_token_expires_at'],
            ]);
        });
    }

    /**
     * @throws RandomException 共享追踪初始化无法生成 UUIDv7 时抛出
     * @throws GRPCException
     */
    public function SocialLogin(ContextInterface $ctx, SocialLoginRequest $in): SocialLoginResponse
    {
        return $this->call($ctx, 'SocialLogin', function () use ($in): SocialLoginResponse {
            $login = $this->authService->socialLogin(
                $this->socialProvider($in->getProvider()),
                trim($in->getCode()),
                $this->deviceInfo($in->getDevice()),
            );

            // 社交登录现在始终注册或恢复用户并返回会话；需要绑定手机号的状态已废弃。
            return new SocialLoginResponse([
                'state' => SocialLoginState::SOCIAL_LOGIN_STATE_AUTHENTICATED,
                'login' => $this->loginResponse($login['login']),
            ]);
        });
    }

    public function SendSocialPhoneCode(
        ContextInterface $ctx,
        SendSocialPhoneCodeRequest $in
    ): SendSocialPhoneCodeResponse {
        // 手机号绑定流程已由认证后的社交登录绑定（BindSocialIdentity）替代。为保持协议兼容保留 RPC 声明，并在调用应用服
        // 务或 OAuth 之前拒绝执行。
        throw $this->rejectedLegacyRpc($ctx, 'SendSocialPhoneCode');
    }

    public function BindSocialPhone(
        ContextInterface $ctx,
        BindSocialPhoneRequest $in
    ): BindSocialPhoneResponse {
        throw $this->rejectedLegacyRpc($ctx, 'BindSocialPhone');
    }

    /**
     * @throws RandomException 共享追踪初始化无法生成 UUIDv7 时抛出
     * @throws GRPCException
     */
    public function BindSocialIdentity(
        ContextInterface $ctx,
        BindSocialIdentityRequest $in
    ): BindSocialIdentityResponse {
        return $this->call($ctx, 'BindSocialIdentity', function () use ($in): BindSocialIdentityResponse {
            $result = $this->authService->bindSocialIdentity(
                $this->socialProvider($in->getProvider()),
                trim($in->getCode()),
                $in->getAccessToken(),
            );

            return new BindSocialIdentityResponse([
                'user_id'  => $this->uint64((string)$result['user_id'], 'user_id'),
                'provider' => $this->socialProviderValue((string)$result['provider']),
            ]);
        });
    }

    /**
     * @throws RandomException 共享追踪初始化无法生成 UUIDv7 时抛出
     * @throws GRPCException
     */
    public function Logout(ContextInterface $ctx, LogoutRequest $in): LogoutResponse
    {
        return $this->call($ctx, 'Logout', function () use ($in): LogoutResponse {
            $refreshToken = $in->getRefreshToken();
            $accessToken = $in->getAccessToken();

            $success = $this->authService->logout(
                $refreshToken,
                $accessToken !== '' ? $accessToken : null
            );

            return new LogoutResponse([
                'success' => $success,
            ]);
        });
    }

    /**
     * @throws RandomException 共享追踪初始化无法生成 UUIDv7 时抛出
     * @throws GRPCException
     */
    public function VerifyToken(ContextInterface $ctx, VerifyTokenRequest $in): VerifyTokenResponse
    {
        return $this->call($ctx, 'VerifyToken', function () use ($in): VerifyTokenResponse {
            $accessToken = $in->getAccessToken();
            $result = $this->authService->verifyToken($accessToken);

            $response = [
                'valid' => $result['valid'],
            ];

            if ($response['valid']) {
                $response['user_id'] = $this->uint64($result['user_id'] ?? '0', 'user_id');
                $response['device_id'] = $this->uint64($result['device_id'] ?? '0', 'device_id');
            }

            return new VerifyTokenResponse($response);
        });
    }

    /**
     * @throws RandomException 共享追踪初始化无法生成 UUIDv7 时抛出
     * @throws GRPCException
     */
    public function CreateWsTicket(ContextInterface $ctx, CreateWsTicketRequest $in): CreateWsTicketResponse
    {
        return $this->call($ctx, 'CreateWsTicket', function () use ($in): CreateWsTicketResponse {
            $userId = (string)$in->getUserId();
            $personaId = (string)$in->getPersonaId();
            $deviceId = $in->getDeviceId();

            $result = $this->authService->createWsTicket($userId, $personaId, $deviceId);

            return new CreateWsTicketResponse([
                'ticket'     => (string)$result['ticket'],
                'expires_at' => (string)$result['expire_at'],
            ]);
        });
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     * @throws GRPCException
     * @throws RandomException 追踪初始化无法生成 UUIDv7 时抛出；此处不捕获，由 RoadRunner gRPC 服务器转换为 UNKNOWN
     * 响应
     */
    private function call(ContextInterface $ctx, string $method, callable $operation): mixed
    {
        $this->authorizer?->authorize($ctx, $method);

        // 仅在 RPC 授权后才信任调用方提供的截止时间；下游各跳预算限制在剩余时间内。
        $this->bindDeadlineContext($ctx);

        // TraceContext::create() 可能抛出 RandomException；有意不捕获，使失败传播到 gRPC 服务器（参见上方 @throws）。
        $trace = TraceContext::create(
            requestId: $this->metadataValue($ctx, 'x-request-id'),
            traceId: $this->metadataValue($ctx, 'x-trace-id'),
            userId: $this->metadataValue($ctx, 'x-user-id'),
            personaId: $this->metadataValue($ctx, 'x-persona-id'),
            deviceId: $this->metadataValue($ctx, 'x-device-id'),
            appVersion: $this->metadataValue($ctx, 'x-app-version'),
            traceparent: $this->metadataValue($ctx, 'traceparent'),
        );
        TraceContext::setCurrent($trace);
        $this->logger->info("Handling Auth gRPC call: $method", ['method' => $method]);

        try {
            return $operation();
        } catch (GRPCException $e) {
            // GRPCException::getCode() 是 gRPC 传输状态（如 INVALID_ARGUMENT = 3），不是业务 ErrorCode，因此不能作为
            // x-app-error-code 传递。网关客户端通过自己的回退映射表处理传输状态。
            throw $e;
        } catch (Throwable $e) {
            // 有意与其他服务的 RoadRunner 传输层保持重复实现：错误代码和尾部元数据映射由各服务自行管理。
            /** @noinspection DuplicatedCode */
            $this->logger->error("Error in $method: " . $e->getMessage(), [
                'exception' => $e::class,
                'error_code' => $e->getCode(),
            ]);

            $error = $this->appErrorCode((int)$e->getCode());
            if ($error !== null) {
                $this->attachAppErrorTrailer($ctx, $error->value);
            }

            throw GRPCException::create(
                $this->publicErrorMessage($e),
                ErrorStatusMapper::forExceptionCode((int)$e->getCode()),
                $e,
            );
        } finally {
            TraceContext::reset();
            DeadlineContext::reset();
        }
    }

    /**
     * RoadRunner 不向 PHP 工作进程暴露传输截止时间，因此网关通过 x-deadline-unix-ms 传递绝对截止时间。无效或缺失值仍
     * 使用配置预算；该值只能缩短下游预算，不能延长，因此行为异常的已授权调用方只能让自己的请求更早超时。
     */
    private function bindDeadlineContext(ContextInterface $ctx): void
    {
        $deadline = $this->metadataValue($ctx, 'x-deadline-unix-ms');
        DeadlineContext::setCurrent(
            $deadline !== null && ctype_digit($deadline) ? (int)$deadline : null
        );
    }

    /**
     * `x-app-error-code` 仅能传递协议定义的 ErrorCode；网关客户端将存在的尾部元数据视为权威业务代码，并完全跳过 gRPC
     * 状态回退。基础设施异常代码（如 PDO 23000 或任意代码）解析为 null；OK（0）也须排除，因为它表示成功，不能作为失
     * 败代码发布。
     */
    private function appErrorCode(int $code): ?ErrorCode
    {
        $error = $code > 0 ? ErrorCode::tryFrom($code) : null;

        return $error === ErrorCode::OK ? null : $error;
    }

    private function attachAppErrorTrailer(ContextInterface $ctx, int $code): void
    {
        $trailers = $ctx->getValue(ResponseTrailers::class);
        if ($trailers instanceof ResponseTrailers) {
            $trailers->set('x-app-error-code', (string)$code);
        }
    }

    private function publicErrorMessage(Throwable $exception): string
    {
        // PDOException::getCode() 在运行时可能返回 SQLSTATE 字符串，尽管 Throwable 声明返回 int；传入有类型约束的
        // appErrorCode() 参数前须在边界规范化。
        /** @noinspection PhpCastIsUnnecessaryInspection */
        $error = $this->appErrorCode((int)$exception->getCode());

        return $error !== null ? $exception->getMessage() : ErrorCode::INTERNAL_SERVER_ERROR->message();
    }

    private function socialProvider(int $provider): SocialProvider
    {
        return match ($provider) {
            1 => SocialProvider::WECHAT,
            2 => SocialProvider::QQ,
            default => throw GRPCException::create('social provider is required', StatusCode::INVALID_ARGUMENT),
        };
    }

    private function socialProviderValue(string $provider): int
    {
        return match ($provider) {
            SocialProvider::WECHAT->value => 1,
            SocialProvider::QQ->value => 2,
            default => throw new RuntimeException(
                ErrorCode::SERVICE_UNAVAILABLE->message(),
                ErrorCode::SERVICE_UNAVAILABLE->value
            ),
        };
    }

    /**
     * 拒绝废弃 RPC，不调用应用服务。
     *
     * 此处直接设置响应尾部元数据，因为失败不会进入 call() 的错误映射；缺少尾部元数据会使网关回退到传输状态，而无法使
     * 用业务代码。
     */
    private function rejectedLegacyRpc(ContextInterface $ctx, string $method): GRPCException
    {
        // 先授权，防止未知调用方获知哪些 RPC 存在。
        $this->authorizer?->authorize($ctx, $method);

        $this->logger->warning("Rejected deprecated Auth gRPC call: $method", ['method' => $method]);
        $this->attachAppErrorTrailer($ctx, ErrorCode::AUTH_UNAUTHORIZED->value);

        return GRPCException::create(
            ErrorCode::AUTH_UNAUTHORIZED->message(),
            StatusCode::UNAUTHENTICATED
        );
    }

    /** @param array<string, mixed> $result */
    private function loginResponse(array $result): LoginResponse
    {
        return new LoginResponse([
            'user_id'                  => $this->uint64((string)$result['user_id'], 'user_id'),
            'access_token'             => (string)$result['access_token'],
            'refresh_token'            => (string)$result['refresh_token'],
            'access_token_expires_at'  => (string)$result['access_token_expires_at'],
            'refresh_token_expires_at' => (string)$result['refresh_token_expires_at'],
            'is_new_user'              => (bool)($result['is_new_user'] ?? false),
        ]);
    }

    private function metadataValue(ContextInterface $ctx, string $key): ?string
    {
        // 查找顺序与 InternalRpcAuthorizer 相同；认证传输层保留自己的读取逻辑，此处不进行跨服务提取。
        /** @noinspection DuplicatedCode */
        $values = $ctx->getValues();
        $value = $values[$key] ?? null;
        if ($value === null && is_array($values['metadata'] ?? null)) {
            $value = $values['metadata'][$key] ?? null;
        }
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_scalar($value) && (string)$value !== '' ? (string)$value : null;
    }

    /**
     * @return array{device_uuid: string, platform: string, app_version: string, push_token: ?string}
     */
    private function deviceInfo(?DeviceInfo $device): array
    {
        return [
            'device_uuid' => $device?->getDeviceUuid() ?? '',
            'platform'    => $device?->getPlatform() ?? '',
            'app_version' => $device?->getAppVersion() ?? '',
            'push_token'  => ($pushToken = $device?->getPushToken()) !== '' ? $pushToken : null,
        ];
    }

    private function uint64(int|string $value, string $field): string
    {
        $value = (string)$value;
        $value = ltrim($value, '0') ?: '0';

        if (!ctype_digit($value)) {
            throw GRPCException::create("$field must be a uint64", StatusCode::INVALID_ARGUMENT);
        }

        if (strlen($value) > 20 || (strlen($value) === 20 && strcmp($value, '18446744073709551615') > 0)) {
            throw GRPCException::create("$field must be a uint64", StatusCode::INVALID_ARGUMENT);
        }

        return $value;
    }
}
