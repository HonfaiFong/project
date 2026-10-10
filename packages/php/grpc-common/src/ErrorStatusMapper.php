<?php

declare(strict_types=1);

namespace Packages\GrpcCommon;

use Contracts\Errors\ErrorCode;
use Spiral\RoadRunner\GRPC\StatusCode;

/**
 * 将协议 ErrorCode 映射为 gRPC 传输状态，供错误路径使用。
 *
 * 此类专用于失败映射，不能作为通用的 ErrorCode -> StatusCode 映射；`ErrorCode::OK` 表示成功，因此会映射为
 * INTERNAL。仅用于即将抛出的失败。
 *
 * 此前各服务维护独立映射表（认证服务的 `statusFor()` 和用户服务的 `grpcStatus()`），已经产生偏差：未映射代码本应
 * 回退为 INTERNAL，但认证表新增的 `$code === 0 ? INTERNAL : UNKNOWN` 分支将 INTERNAL_SERVER_ERROR(9001001) 报告
 * 为 UNKNOWN，用户表则遗漏了其他领域的代码。统一映射表可防止此类偏差。
 *
 * 业务代码决定传输状态；缺失代码或非协议 ErrorCode（如 PDO 23000、任意库代码）属于本服务无法分类的故障，统一报告
 * 为 INTERNAL。
 *
 * 此状态仅在缺少 `x-app-error-code` 尾部元数据时指导网关分类失败，本身不是业务代码。
 */
final class ErrorStatusMapper
{
    /**
     * 声明字面量联合类型，使调用方可直接将结果传给 GRPCException::create() 的同类型 $code 参数。
     *
     * @param ErrorCode|null $error
     * @return int
     * @phpstan-return 3|4|5|6|7|8|10|13|14|16
     */
    public static function forFailure(?ErrorCode $error): int
    {
        if ($error === null || $error === ErrorCode::OK) {
            return StatusCode::INTERNAL;
        }

        return match ($error) {
            ErrorCode::INVALID_ARGUMENT,
            ErrorCode::AUTH_DEVICE_INVALID,
            ErrorCode::AUTH_SOCIAL_PROVIDER_UNSUPPORTED,
            ErrorCode::AUTH_TICKET_EXPIRED => StatusCode::INVALID_ARGUMENT,

            ErrorCode::AUTH_INVALID_CREDENTIALS,
            ErrorCode::AUTH_TOKEN_EXPIRED,
            ErrorCode::AUTH_TOKEN_INVALID,
            ErrorCode::AUTH_TOKEN_REVOKED,
            ErrorCode::AUTH_UNAUTHORIZED,
            ErrorCode::AUTH_SOCIAL_CODE_INVALID,
            ErrorCode::AUTH_SOCIAL_BIND_TOKEN_INVALID,
            ErrorCode::AUTH_SMS_CODE_INVALID,
            ErrorCode::AUTH_SMS_CODE_EXPIRED => StatusCode::UNAUTHENTICATED,

            ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR,
            ErrorCode::SERVICE_UNAVAILABLE => StatusCode::UNAVAILABLE,

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
            ErrorCode::MESSAGE_DUPLICATE_CLIENT_ID,
            ErrorCode::AUTH_SOCIAL_PROVIDER_ALREADY_BOUND,
            ErrorCode::AUTH_SOCIAL_BIND_CONFLICT,
            ErrorCode::AUTH_SOCIAL_BIND_PHONE_CHANGED => StatusCode::ALREADY_EXISTS,

            ErrorCode::IDEMPOTENCY_CONFLICT => StatusCode::ABORTED,

            ErrorCode::PERSONA_LIMIT_EXCEEDED,
            ErrorCode::RATE_LIMIT_EXCEEDED => StatusCode::RESOURCE_EXHAUSTED,

            ErrorCode::RPC_TIMEOUT => StatusCode::DEADLINE_EXCEEDED,

            default => StatusCode::INTERNAL,
        };
    }

    /**
     * 映射原始异常代码。ErrorCode::tryFrom(0) 返回 ErrorCode::OK 而非 null，因此须显式排除成功代码；委托
     * forFailure() 处理，避免重复空值检查。
     *
     * @param int $code
     * @return int
     * @phpstan-return 3|4|5|6|7|8|10|13|14|16
     */
    public static function forExceptionCode(int $code): int
    {
        return self::forFailure($code > 0 ? ErrorCode::tryFrom($code) : null);
    }
}
