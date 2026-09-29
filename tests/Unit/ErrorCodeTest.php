<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Errors\ErrorCode;
use Tests\TestCase;

final class ErrorCodeTest extends TestCase
{
    public function testErrorCodesDomainSeparation(): void
    {
        $this->assertSame(0, ErrorCode::OK->value);
        $this->assertStringStartsWith('100', (string)ErrorCode::AUTH_INVALID_CREDENTIALS->value);
        $this->assertStringStartsWith('200', (string)ErrorCode::USER_NOT_FOUND->value);
        $this->assertStringStartsWith('300', (string)ErrorCode::CONTENT_POST_NOT_FOUND->value);
        $this->assertStringStartsWith('400', (string)ErrorCode::SOCIAL_CANNOT_FOLLOW_SELF->value);
        $this->assertStringStartsWith('500', (string)ErrorCode::CHAT_CONVERSATION_NOT_FOUND->value);
        $this->assertStringStartsWith('600', (string)ErrorCode::MESSAGE_NOT_FOUND->value);
        $this->assertStringStartsWith('700', (string)ErrorCode::RISK_ACTION_DENIED->value);
        $this->assertStringStartsWith('800', (string)ErrorCode::MODERATION_REJECTED->value);
        $this->assertStringStartsWith('900', (string)ErrorCode::INTERNAL_SERVER_ERROR->value);
    }

    public function testHttpStatusMapping(): void
    {
        $this->assertSame(200, ErrorCode::OK->httpStatus());
        $this->assertSame(401, ErrorCode::AUTH_UNAUTHORIZED->httpStatus());
        $this->assertSame(401, ErrorCode::AUTH_INVALID_CREDENTIALS->httpStatus());
        $this->assertSame(403, ErrorCode::PERSONA_FORBIDDEN->httpStatus());
        $this->assertSame(404, ErrorCode::USER_NOT_FOUND->httpStatus());
        $this->assertSame(409, ErrorCode::USER_ALREADY_EXISTS->httpStatus());
        $this->assertSame(429, ErrorCode::RATE_LIMIT_EXCEEDED->httpStatus());
    }
}
