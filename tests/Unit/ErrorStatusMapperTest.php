<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Errors\ErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use Packages\GrpcCommon\ErrorStatusMapper;
use Spiral\RoadRunner\GRPC\StatusCode;
use Tests\TestCase;

/**
 * 固定 ErrorCode -> gRPC 传输状态的协议约定。
 *
 * 认证和用户服务共用此映射表；若静默修改 match 分支，会改变两个服务的传输协议，而其他测试可能仍然通过。
 */
final class ErrorStatusMapperTest extends TestCase
{
    #[DataProvider('exceptionCodes')]
    public function testExceptionCodeMapping(int $code, int $expected): void
    {
        $this->assertSame($expected, ErrorStatusMapper::forExceptionCode($code), "raw code {$code}");
    }

    /**
     * 缺失代码表示本服务无法分类的失败。
     */
    public function testNullFailureIsInternal(): void
    {
        $this->assertSame(StatusCode::INTERNAL, ErrorStatusMapper::forFailure(null));
    }

    /**
     * 映射器用于错误路径，因此 OK 绝不能作为传输状态报告。
     */
    public function testOkIsTreatedAsAFailureNotAsSuccess(): void
    {
        $this->assertSame(StatusCode::INTERNAL, ErrorStatusMapper::forFailure(ErrorCode::OK));
        $this->assertSame(StatusCode::INTERNAL, ErrorStatusMapper::forExceptionCode(ErrorCode::OK->value));
    }

    /**
     * 每个 ErrorCode 枚举项须映射为明确状态；新增枚举项却未确定传输状态时，测试应失败，不能静默回退为 INTERNAL。
     */
    public function testEveryErrorCodeHasADeliberateStatus(): void
    {
        $expected = self::expectedStatuses();
        $undecided = [];

        foreach (ErrorCode::cases() as $case) {
            if (!isset($expected[$case->name])) {
                $undecided[] = $case->name;
                continue;
            }

            $this->assertSame(
                $expected[$case->name],
                ErrorStatusMapper::forFailure($case),
                sprintf('Unexpected gRPC status for %s', $case->name)
            );
        }

        $this->assertSame(
            [],
            $undecided,
            'New ErrorCode cases need an explicit gRPC status decision (see ' . self::class . ')'
        );
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function exceptionCodes(): array
    {
        return [
            'success code 0'         => [0, StatusCode::INTERNAL],
            'business code'          => [ErrorCode::USER_NOT_FOUND->value, StatusCode::NOT_FOUND],
            'auth business code'     => [ErrorCode::AUTH_INVALID_CREDENTIALS->value, StatusCode::UNAUTHENTICATED],
            'internal server error'  => [ErrorCode::INTERNAL_SERVER_ERROR->value, StatusCode::INTERNAL],
            'pdo duplicate entry'    => [23000, StatusCode::INTERNAL],
            'arbitrary library code' => [1234, StatusCode::INTERNAL],
            'negative code'          => [-5, StatusCode::INTERNAL],
        ];
    }

    /**
     * 传输协议，按各代码须产生的状态分组。
     *
     * @return array<string, int>
     */
    private static function expectedStatuses(): array
    {
        $groups = [
            StatusCode::INVALID_ARGUMENT => [
                ErrorCode::INVALID_ARGUMENT,
                ErrorCode::AUTH_DEVICE_INVALID,
                ErrorCode::AUTH_TICKET_EXPIRED,
                ErrorCode::AUTH_SOCIAL_PROVIDER_UNSUPPORTED,
            ],
            StatusCode::UNAUTHENTICATED => [
                ErrorCode::AUTH_INVALID_CREDENTIALS,
                ErrorCode::AUTH_TOKEN_EXPIRED,
                ErrorCode::AUTH_TOKEN_INVALID,
                ErrorCode::AUTH_TOKEN_REVOKED,
                ErrorCode::AUTH_UNAUTHORIZED,
                ErrorCode::AUTH_SOCIAL_CODE_INVALID,
                ErrorCode::AUTH_SOCIAL_BIND_TOKEN_INVALID,
                ErrorCode::AUTH_SMS_CODE_INVALID,
                ErrorCode::AUTH_SMS_CODE_EXPIRED,
            ],
            StatusCode::PERMISSION_DENIED => [
                ErrorCode::PERSONA_FORBIDDEN,
                ErrorCode::CHAT_FORBIDDEN_BY_PRIVACY,
                ErrorCode::SOCIAL_USER_BLOCKED,
                ErrorCode::USER_BANNED,
            ],
            StatusCode::NOT_FOUND => [
                ErrorCode::USER_NOT_FOUND,
                ErrorCode::PERSONA_NOT_FOUND,
                ErrorCode::CONTENT_POST_NOT_FOUND,
                ErrorCode::CONTENT_COMMENT_NOT_FOUND,
                ErrorCode::CHAT_CONVERSATION_NOT_FOUND,
                ErrorCode::MESSAGE_NOT_FOUND,
            ],
            StatusCode::ALREADY_EXISTS => [
                ErrorCode::USER_ALREADY_EXISTS,
                ErrorCode::SOCIAL_ALREADY_FOLLOWED,
                ErrorCode::MESSAGE_DUPLICATE_CLIENT_ID,
                ErrorCode::AUTH_SOCIAL_PROVIDER_ALREADY_BOUND,
                ErrorCode::AUTH_SOCIAL_BIND_CONFLICT,
                ErrorCode::AUTH_SOCIAL_BIND_PHONE_CHANGED,
            ],
            StatusCode::ABORTED => [
                ErrorCode::IDEMPOTENCY_CONFLICT,
            ],
            StatusCode::RESOURCE_EXHAUSTED => [
                ErrorCode::PERSONA_LIMIT_EXCEEDED,
                ErrorCode::RATE_LIMIT_EXCEEDED,
            ],
            StatusCode::DEADLINE_EXCEEDED => [
                ErrorCode::RPC_TIMEOUT,
            ],
            StatusCode::UNAVAILABLE => [
                ErrorCode::AUTH_SOCIAL_UPSTREAM_ERROR,
                ErrorCode::SERVICE_UNAVAILABLE,
            ],
            StatusCode::INTERNAL => [
                // OK 只会通过错误路径到达映射器。
                ErrorCode::OK,
                ErrorCode::INTERNAL_SERVER_ERROR,
                // 有意未映射的业务代码。
                ErrorCode::CONTENT_POST_DELETED,
                ErrorCode::CONTENT_UNDER_REVIEW,
                ErrorCode::SOCIAL_CANNOT_FOLLOW_SELF,
                ErrorCode::SOCIAL_CANNOT_BLOCK_SELF,
                ErrorCode::CHAT_NOT_A_MEMBER,
                ErrorCode::MESSAGE_SEND_FAILED,
                ErrorCode::RISK_ACTION_DENIED,
                ErrorCode::RISK_CHALLENGE_REQUIRED,
                ErrorCode::MODERATION_REJECTED,
            ],
        ];

        $statuses = [];
        foreach ($groups as $status => $cases) {
            foreach ($cases as $case) {
                $statuses[$case->name] = $status;
            }
        }

        return $statuses;
    }
}
