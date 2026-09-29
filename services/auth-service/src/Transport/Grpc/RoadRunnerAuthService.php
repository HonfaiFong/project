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
use Contracts\Gen\Auth\V1\LogoutRequest;
use Contracts\Gen\Auth\V1\LogoutResponse;
use Contracts\Gen\Auth\V1\RefreshTokenRequest;
use Contracts\Gen\Auth\V1\RefreshTokenResponse;
use Contracts\Gen\Auth\V1\VerifyTokenRequest;
use Contracts\Gen\Auth\V1\VerifyTokenResponse;
use InvalidArgumentException;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\ResponseTrailers;
use Spiral\RoadRunner\GRPC\StatusCode;
use Throwable;

final class RoadRunnerAuthService implements AuthServiceInterface
{
    public function __construct(private readonly AuthGrpcHandler $handler)
    {
    }

    public function Login(ContextInterface $ctx, LoginRequest $in): LoginResponse
    {
        return $this->execute(
            $ctx,
            'Login',
            [
                'phone'    => $in->getPhone(),
                'code'     => $in->getCode(),
                'password' => $in->getPassword(),
                'device'   => $this->deviceInfo($in->getDevice()),
            ],
            fn (array $result): LoginResponse => new LoginResponse([
                'user_id'                  => $this->uint64($result['user_id'] ?? '0', 'user_id'),
                'access_token'             => (string)($result['access_token'] ?? ''),
                'refresh_token'            => (string)($result['refresh_token'] ?? ''),
                'access_token_expires_at'  => (string)($result['access_token_expires_at'] ?? ''),
                'refresh_token_expires_at' => (string)($result['refresh_token_expires_at'] ?? ''),
                'is_new_user'              => (bool)($result['is_new_user'] ?? false),
            ])
        );
    }

    public function RefreshToken(ContextInterface $ctx, RefreshTokenRequest $in): RefreshTokenResponse
    {
        return $this->execute(
            $ctx,
            'RefreshToken',
            [
                'refresh_token' => $in->getRefreshToken(),
                'device'        => $this->deviceInfo($in->getDevice()),
            ],
            static fn (array $result): RefreshTokenResponse => new RefreshTokenResponse([
                'access_token'             => (string)$result['access_token'],
                'refresh_token'            => (string)$result['refresh_token'],
                'access_token_expires_at'  => (string)$result['access_token_expires_at'],
                'refresh_token_expires_at' => (string)$result['refresh_token_expires_at'],
            ])
        );
    }

    public function Logout(ContextInterface $ctx, LogoutRequest $in): LogoutResponse
    {
        return $this->execute(
            $ctx,
            'Logout',
            [
                'refresh_token' => $in->getRefreshToken(),
                'access_token'  => $in->getAccessToken(),
            ],
            static fn (array $result): LogoutResponse => new LogoutResponse([
                'success' => (bool)($result['success'] ?? false),
            ])
        );
    }

    public function VerifyToken(ContextInterface $ctx, VerifyTokenRequest $in): VerifyTokenResponse
    {
        return $this->execute(
            $ctx,
            'VerifyToken',
            ['access_token' => $in->getAccessToken()],
            function (array $result): VerifyTokenResponse {
                $response = [
                    'valid' => (bool)($result['valid'] ?? false),
                ];

                if ($response['valid']) {
                    $response['user_id'] = $this->uint64($result['user_id'] ?? '0', 'user_id');
                    $response['device_id'] = $this->uint64($result['device_id'] ?? '0', 'device_id');
                }

                return new VerifyTokenResponse($response);
            }
        );
    }

    public function CreateWsTicket(ContextInterface $ctx, CreateWsTicketRequest $in): CreateWsTicketResponse
    {
        return $this->execute(
            $ctx,
            'CreateWsTicket',
            [
                'user_id'    => (string)$in->getUserId(),
                'persona_id' => (string)$in->getPersonaId(),
                'device_id'  => $in->getDeviceId(),
            ],
            static fn (array $result): CreateWsTicketResponse => new CreateWsTicketResponse([
                'ticket'     => (string)$result['ticket'],
                'expires_at' => (string)$result['expires_at'],
            ])
        );
    }

    /**
     * @param array<string, mixed> $request
     * @param callable(array<string, mixed>): object $responseFactory
     */
    private function execute(
        ContextInterface $ctx,
        string $method,
        array $request,
        callable $responseFactory
    ): object {
        try {
            $result = $this->handler->handle($method, $request, $this->metadata($ctx));
            $code = (int)($result['code'] ?? ErrorCode::OK->value);

            // "Is this a failure?" and "may this code be published?" are separate questions:
            // a non-zero code that is not a contract ErrorCode (PDO 23000, library codes) is
            // still a failure and must never fall through to the success response factory.
            if ($code !== ErrorCode::OK->value) {
                $error = $this->appErrorCode($code);

                if ($error !== null) {
                    $this->attachAppErrorTrailer($ctx, $error->value);
                }

                $this->throwMappedError($error);
            }

            return $responseFactory($result);
        } catch (GRPCException $e) {
            throw $e;
        } catch (Throwable $e) {
            $error = $this->appErrorCode((int)$e->getCode());
            if ($error !== null) {
                $this->attachAppErrorTrailer($ctx, $error->value);
            }

            throw $this->mapThrowable($e);
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

    private function throwMappedError(?ErrorCode $error): never
    {
        if ($error === null) {
            // Unknown non-zero handler code: this is a fault inside this service, not an
            // unclassifiable one, so report INTERNAL rather than UNKNOWN and fail closed
            // instead of letting the caller treat the RPC as succeeded.
            throw GRPCException::create(
                ErrorCode::INTERNAL_SERVER_ERROR->message(),
                StatusCode::INTERNAL
            );
        }

        throw GRPCException::create($error->message(), $this->statusFor($error));
    }

    private function mapThrowable(Throwable $e): GRPCException
    {
        if ($e instanceof InvalidArgumentException) {
            return GRPCException::create('invalid request arguments', StatusCode::INVALID_ARGUMENT, $e);
        }

        $error = $this->appErrorCode((int)$e->getCode());
        $status = $this->statusFor($error);
        $message = $error?->message() ?? ErrorCode::INTERNAL_SERVER_ERROR->message();

        return GRPCException::create($message, $status, $e);
    }

    /**
     * Business codes decide the transport status; absent or unmapped internal failures are
     * always INTERNAL. Deriving this from the raw exception integer (the old `$code === 0`
     * check) made INTERNAL_SERVER_ERROR(9001001) fall through to UNKNOWN(2), so the two
     * internal-failure paths disagreed: a normalised PDO 23000 reported INTERNAL while a
     * normalised RuntimeException(code=0) reported UNKNOWN.
     *
     * @return 0|1|2|3|4|5|6|7|8|9|10|11|12|13|14|15|16
     */
    private function statusFor(?ErrorCode $error): int
    {
        return match ($error) {
            ErrorCode::INVALID_ARGUMENT,
            ErrorCode::AUTH_DEVICE_INVALID,
            ErrorCode::AUTH_TICKET_EXPIRED => StatusCode::INVALID_ARGUMENT,
            ErrorCode::AUTH_INVALID_CREDENTIALS,
            ErrorCode::AUTH_TOKEN_EXPIRED,
            ErrorCode::AUTH_TOKEN_INVALID,
            ErrorCode::AUTH_TOKEN_REVOKED,
            ErrorCode::AUTH_UNAUTHORIZED => StatusCode::UNAUTHENTICATED,
            ErrorCode::PERSONA_FORBIDDEN,
            ErrorCode::CHAT_FORBIDDEN_BY_PRIVACY,
            ErrorCode::SOCIAL_USER_BLOCKED => StatusCode::PERMISSION_DENIED,
            ErrorCode::USER_NOT_FOUND,
            ErrorCode::PERSONA_NOT_FOUND,
            ErrorCode::CONTENT_POST_NOT_FOUND,
            ErrorCode::CONTENT_COMMENT_NOT_FOUND,
            ErrorCode::CHAT_CONVERSATION_NOT_FOUND,
            ErrorCode::MESSAGE_NOT_FOUND => StatusCode::NOT_FOUND,
            ErrorCode::USER_ALREADY_EXISTS => StatusCode::ALREADY_EXISTS,
            ErrorCode::IDEMPOTENCY_CONFLICT => StatusCode::ABORTED,
            ErrorCode::RATE_LIMIT_EXCEEDED => StatusCode::RESOURCE_EXHAUSTED,
            ErrorCode::RPC_TIMEOUT => StatusCode::DEADLINE_EXCEEDED,
            ErrorCode::SERVICE_UNAVAILABLE => StatusCode::UNAVAILABLE,
            // INTERNAL_SERVER_ERROR, an absent code (null) and every unmapped code are all
            // "the service could not classify this failure", so they share INTERNAL.
            default => StatusCode::INTERNAL,
        };
    }

    /**
     * RoadRunner passes incoming gRPC metadata as arrays of strings in the
     * context values. Keep scalar values compatible with local test contexts.
     *
     * @return array<string, array<int, string>>
     */
    private function metadata(ContextInterface $ctx): array
    {
        $values = $ctx->getValues();
        $rawMetadata = $values['metadata'] ?? $values['grpc.metadata'] ?? $values;

        if (!is_array($rawMetadata)) {
            return [];
        }

        $metadata = [];
        foreach ($rawMetadata as $key => $value) {
            $normalizedKey = strtolower((string)$key);

            if (is_array($value)) {
                $metadata[$normalizedKey] = array_values(array_filter(
                    array_map(static fn (mixed $item): ?string => is_scalar($item) ? (string)$item : null, $value),
                    static fn (?string $item): bool => $item !== null
                ));
            } elseif (is_scalar($value)) {
                $metadata[$normalizedKey] = [(string)$value];
            }
        }

        return $metadata;
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
            throw new InvalidArgumentException("{$field} must be a uint64");
        }

        if (strlen($value) > 20 || (strlen($value) === 20 && strcmp($value, '18446744073709551615') > 0)) {
            throw new InvalidArgumentException("{$field} must be a uint64");
        }

        return $value;
    }
}
