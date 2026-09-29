<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Errors\ErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use Packages\GrpcCommon\ErrorStatusMapper;
use Spiral\RoadRunner\GRPC\StatusCode;
use Tests\TestCase;

/**
 * Pins the ErrorCode -> gRPC transport status contract.
 *
 * This one table is shared by the auth and user services, so a silent edit to its match arms
 * would change the transport contract for both services while every other test stayed green.
 */
final class ErrorStatusMapperTest extends TestCase
{
    #[DataProvider('exceptionCodes')]
    public function testExceptionCodeMapping(int $code, int $expected): void
    {
        $this->assertSame($expected, ErrorStatusMapper::forExceptionCode($code), "raw code {$code}");
    }

    /**
     * An absent code is a failure this service cannot classify.
     */
    public function testNullFailureIsInternal(): void
    {
        $this->assertSame(StatusCode::INTERNAL, ErrorStatusMapper::forFailure(null));
    }

    /**
     * The mapper is an error-path mapper, so OK must never be reported as a transport status.
     */
    public function testOkIsTreatedAsAFailureNotAsSuccess(): void
    {
        $this->assertSame(StatusCode::INTERNAL, ErrorStatusMapper::forFailure(ErrorCode::OK));
        $this->assertSame(StatusCode::INTERNAL, ErrorStatusMapper::forExceptionCode(ErrorCode::OK->value));
    }

    /**
     * Every ErrorCode case must map to a concrete status. Adding an enum case without deciding
     * its transport status therefore fails this test instead of silently becoming INTERNAL.
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
     * The transport contract, grouped by the status each code must produce.
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
            ],
            StatusCode::UNAUTHENTICATED => [
                ErrorCode::AUTH_INVALID_CREDENTIALS,
                ErrorCode::AUTH_TOKEN_EXPIRED,
                ErrorCode::AUTH_TOKEN_INVALID,
                ErrorCode::AUTH_TOKEN_REVOKED,
                ErrorCode::AUTH_UNAUTHORIZED,
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
                ErrorCode::SERVICE_UNAVAILABLE,
            ],
            StatusCode::INTERNAL => [
                // OK only ever reaches the mapper through the error path.
                ErrorCode::OK,
                ErrorCode::INTERNAL_SERVER_ERROR,
                // Deliberately unmapped business codes.
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
