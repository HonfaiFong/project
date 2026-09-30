<?php

declare(strict_types=1);

namespace Services\AuthService\Transport\Grpc;

use Contracts\Errors\ErrorCode;
use Contracts\Gen\Auth\V1\AuthServiceInterface;
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
use Contracts\Gen\Auth\V1\LogoutRequest;
use Contracts\Gen\Auth\V1\LogoutResponse;
use Contracts\Gen\Auth\V1\RefreshTokenRequest;
use Contracts\Gen\Auth\V1\RefreshTokenResponse;
use Contracts\Gen\Auth\V1\VerifyTokenRequest;
use Contracts\Gen\Auth\V1\VerifyTokenResponse;
use Packages\GrpcCommon\ErrorStatusMapper;
use Psr\Log\LoggerInterface;
use Packages\Tracing\TraceContext;
use Services\AuthService\Application\Service\AuthApplicationService;
use Services\AuthService\Domain\Auth\SocialProvider;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\ResponseTrailers;
use Spiral\RoadRunner\GRPC\StatusCode;
use Throwable;

final class RoadRunnerAuthService implements AuthServiceInterface
{
    public function __construct(
        private readonly AuthApplicationService $authService,
        private readonly LoggerInterface $logger
    ) {
    }

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

    public function SocialLogin(ContextInterface $ctx, SocialLoginRequest $in): SocialLoginResponse
    {
        return $this->call($ctx, 'SocialLogin', function () use ($in): SocialLoginResponse {
            $result = $this->authService->socialLogin(
                $this->socialProvider($in->getProvider()),
                trim($in->getCode()),
                $this->deviceInfo($in->getDevice()),
            );

            $response = new SocialLoginResponse([
                'state' => $result['state'] === 'authenticated' ? 1 : 2,
            ]);

            if ($result['state'] === 'authenticated') {
                $response->setLogin($this->loginResponse($result['login']));
            } else {
                $response
                    ->setBindToken((string)$result['bind_token'])
                    ->setBindTokenExpiresAt((int)$result['bind_token_expires_at']);
            }

            return $response;
        });
    }

    public function SendSocialPhoneCode(
        ContextInterface $ctx,
        SendSocialPhoneCodeRequest $in
    ): SendSocialPhoneCodeResponse {
        return $this->call($ctx, 'SendSocialPhoneCode', function () use ($ctx, $in): SendSocialPhoneCodeResponse {
            $result = $this->authService->sendSocialPhoneCode(
                trim($in->getBindToken()),
                trim($in->getPhone()),
                [
                    'ip'          => $this->metadataValue($ctx, 'x-client-ip'),
                    'device_uuid' => $this->metadataValue($ctx, 'x-device-id'),
                ],
            );

            return new SendSocialPhoneCodeResponse([
                'expires_in'   => $result['expires_in'],
                'resend_after' => $result['resend_after'],
            ]);
        });
    }

    public function BindSocialPhone(
        ContextInterface $ctx,
        BindSocialPhoneRequest $in
    ): BindSocialPhoneResponse {
        return $this->call($ctx, 'BindSocialPhone', function () use ($in): BindSocialPhoneResponse {
            $result = $this->authService->bindSocialPhone(
                trim($in->getBindToken()),
                trim($in->getPhone()),
                trim($in->getCode()),
                $this->deviceInfo($in->getDevice()),
            );

            return new BindSocialPhoneResponse([
                'login' => $this->loginResponse($result['login']),
            ]);
        });
    }

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
                'success' => (bool)$success,
            ]);
        });
    }

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
     */
    private function call(ContextInterface $ctx, string $method, callable $operation): mixed
    {
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
        $this->logger->info("Handling Auth gRPC call: {$method}", ['method' => $method]);

        try {
            return $operation();
        } catch (GRPCException $e) {
            // GRPCException::getCode() is the gRPC transport status (e.g. INVALID_ARGUMENT = 3),
            // never a business ErrorCode, so it must not be propagated as x-app-error-code.
            // The gateway client maps the transport status via its own fallback table.
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error("Error in {$method}: " . $e->getMessage(), [
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
        }
    }

    /**
     * Only contract ErrorCode values may travel in `x-app-error-code`: the gateway client treats a
     * present trailer as the authoritative business code and skips its gRPC status fallback
     * entirely. Infrastructure exception codes (PDO 23000, arbitrary codes) resolve to null, and
     * OK (0) is excluded too -- it means success, not a failure to publish.
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
            throw GRPCException::create("{$field} must be a uint64", StatusCode::INVALID_ARGUMENT);
        }

        if (strlen($value) > 20 || (strlen($value) === 20 && strcmp($value, '18446744073709551615') > 0)) {
            throw GRPCException::create("{$field} must be a uint64", StatusCode::INVALID_ARGUMENT);
        }

        return $value;
    }
}
