<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

use Contracts\Errors\ErrorCode;
use Spiral\RoadRunner\GRPC\StatusCode;

/**
 * Single source of truth for the ErrorCode -> gRPC transport status mapping.
 *
 * Every service previously kept a private copy of this table (auth's `statusFor()` and the user
 * service's `grpcStatus()`), and the copies had already drifted: both services are supposed to
 * fall back to INTERNAL for an unmapped code, but the auth table grew an extra
 * `$code === 0 ? INTERNAL : UNKNOWN` branch that reported INTERNAL_SERVER_ERROR(9001001) as
 * UNKNOWN. Keeping one table prevents that class of drift.
 *
 * Business codes decide the transport status; a code that is not a contract ErrorCode (a PDO
 * 23000, an arbitrary library code) or that is absent is a fault this service cannot classify
 * and is therefore reported as INTERNAL.
 *
 * This status only tells the gateway how to classify the failure when the `x-app-error-code`
 * trailer is absent; it is never itself a business code.
 */
final class ErrorStatusMapper
{
    /**
     * The literal union is declared so callers can hand the result straight to
     * GRPCException::create(), whose $code parameter is typed as that union.
     *
     * @return 3|4|5|6|7|8|10|13|14|16
     */
    public static function for(?ErrorCode $error): int
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
            ErrorCode::SOCIAL_USER_BLOCKED,
            ErrorCode::USER_BANNED => StatusCode::PERMISSION_DENIED,

            ErrorCode::USER_NOT_FOUND,
            ErrorCode::PERSONA_NOT_FOUND,
            ErrorCode::CONTENT_POST_NOT_FOUND,
            ErrorCode::CONTENT_COMMENT_NOT_FOUND,
            ErrorCode::CHAT_CONVERSATION_NOT_FOUND,
            ErrorCode::MESSAGE_NOT_FOUND => StatusCode::NOT_FOUND,

            ErrorCode::USER_ALREADY_EXISTS,
            ErrorCode::SOCIAL_ALREADY_FOLLOWED,
            ErrorCode::MESSAGE_DUPLICATE_CLIENT_ID => StatusCode::ALREADY_EXISTS,

            ErrorCode::IDEMPOTENCY_CONFLICT => StatusCode::ABORTED,

            ErrorCode::PERSONA_LIMIT_EXCEEDED,
            ErrorCode::RATE_LIMIT_EXCEEDED => StatusCode::RESOURCE_EXHAUSTED,

            ErrorCode::RPC_TIMEOUT => StatusCode::DEADLINE_EXCEEDED,

            ErrorCode::SERVICE_UNAVAILABLE => StatusCode::UNAVAILABLE,

            default => StatusCode::INTERNAL,
        };
    }

    /**
     * Maps a raw exception code. Anything that is not a contract ErrorCode -- including 0 and
     * infrastructure codes such as PDO's 23000 -- resolves to null and therefore to INTERNAL.
     *
     * @return 3|4|5|6|7|8|10|13|14|16
     */
    public static function forCode(int $code): int
    {
        return self::for(ErrorCode::tryFrom($code));
    }
}
