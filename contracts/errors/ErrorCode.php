<?php

declare(strict_types=1);

namespace Contracts\Errors;

enum ErrorCode: int
{
    // Success
    case OK = 0;

    // 100xxxx: Auth Domain
    case AUTH_INVALID_CREDENTIALS = 1001001;
    case AUTH_TOKEN_EXPIRED       = 1001002;
    case AUTH_TOKEN_INVALID       = 1001003;
    case AUTH_TOKEN_REVOKED       = 1001004;
    case AUTH_UNAUTHORIZED          = 1001005;
    case AUTH_DEVICE_INVALID      = 1001006;
    case AUTH_TICKET_EXPIRED      = 1001007;
    case AUTH_SOCIAL_PROVIDER_UNSUPPORTED = 1001008;
    case AUTH_SOCIAL_CODE_INVALID        = 1001009;
    case AUTH_SOCIAL_UPSTREAM_ERROR      = 1001010;
    case AUTH_SOCIAL_BIND_TOKEN_INVALID  = 1001011;
    case AUTH_SMS_CODE_INVALID           = 1001012;
    case AUTH_SMS_CODE_EXPIRED           = 1001013;
    case AUTH_SOCIAL_PROVIDER_ALREADY_BOUND = 1001014;
    case AUTH_SOCIAL_BIND_CONFLICT       = 1001015;
    case AUTH_SOCIAL_BIND_PHONE_CHANGED  = 1001016;

    // 200xxxx: User Domain
    case USER_NOT_FOUND           = 2001001;
    case USER_ALREADY_EXISTS      = 2001002;
    case USER_BANNED              = 2001003;
    case PERSONA_NOT_FOUND        = 2002001;
    case PERSONA_FORBIDDEN        = 2002002;
    case PERSONA_LIMIT_EXCEEDED   = 2002003;

    // 300xxxx: Content Domain
    case CONTENT_POST_NOT_FOUND   = 3001001;
    case CONTENT_POST_DELETED     = 3001002;
    case CONTENT_COMMENT_NOT_FOUND= 3002001;
    case CONTENT_UNDER_REVIEW     = 3003001;

    // 400xxxx: Social Domain
    case SOCIAL_CANNOT_FOLLOW_SELF= 4001001;
    case SOCIAL_ALREADY_FOLLOWED  = 4001002;
    case SOCIAL_USER_BLOCKED      = 4002001;
    case SOCIAL_CANNOT_BLOCK_SELF = 4002002;

    // 500xxxx: Chat Domain
    case CHAT_CONVERSATION_NOT_FOUND = 5001001;
    case CHAT_NOT_A_MEMBER          = 5001002;
    case CHAT_FORBIDDEN_BY_PRIVACY  = 5001003;

    // 600xxxx: Message Domain
    case MESSAGE_NOT_FOUND        = 6001001;
    case MESSAGE_DUPLICATE_CLIENT_ID = 6001002;
    case MESSAGE_SEND_FAILED      = 6001003;

    // 700xxxx: Risk Domain
    case RISK_ACTION_DENIED       = 7001001;
    case RISK_CHALLENGE_REQUIRED  = 7001002;

    // 800xxxx: Moderation Domain
    case MODERATION_REJECTED      = 8001001;

    // 900xxxx: Infrastructure & Common
    case INTERNAL_SERVER_ERROR    = 9001001;
    case INVALID_ARGUMENT         = 9001002;
    case RPC_TIMEOUT              = 9001003;
    case RATE_LIMIT_EXCEEDED      = 9001004;
    case IDEMPOTENCY_CONFLICT     = 9001005;
    case SERVICE_UNAVAILABLE      = 9001006;

    public function message(): string
    {
        return match ($this) {
            self::OK => 'ok',
            self::AUTH_INVALID_CREDENTIALS => 'invalid phone or credentials',
            self::AUTH_TOKEN_EXPIRED => 'token expired',
            self::AUTH_TOKEN_INVALID => 'token invalid',
            self::AUTH_TOKEN_REVOKED => 'token revoked',
            self::AUTH_UNAUTHORIZED => 'unauthorized',
            self::AUTH_DEVICE_INVALID => 'invalid device parameters',
            self::AUTH_TICKET_EXPIRED => 'websocket ticket expired',
            self::AUTH_SOCIAL_PROVIDER_UNSUPPORTED => 'social provider is not supported',
            self::AUTH_SOCIAL_CODE_INVALID => 'social authorization code is invalid',
            self::AUTH_SOCIAL_UPSTREAM_ERROR => 'social provider is temporarily unavailable',
            self::AUTH_SOCIAL_BIND_TOKEN_INVALID => 'social bind token is invalid or expired',
            self::AUTH_SMS_CODE_INVALID => 'SMS verification code is invalid',
            self::AUTH_SMS_CODE_EXPIRED => 'SMS verification code is expired',
            self::AUTH_SOCIAL_PROVIDER_ALREADY_BOUND => 'social provider is already bound',
            self::AUTH_SOCIAL_BIND_CONFLICT => 'social identity binding conflict',
            self::AUTH_SOCIAL_BIND_PHONE_CHANGED => 'phone cannot be changed for this bind session',
            self::USER_NOT_FOUND => 'user not found',
            self::USER_ALREADY_EXISTS => 'user already exists',
            self::USER_BANNED => 'user account is banned',
            self::PERSONA_NOT_FOUND => 'persona not found',
            self::PERSONA_FORBIDDEN => 'forbidden persona access',
            self::PERSONA_LIMIT_EXCEEDED => 'maximum personas limit reached',
            self::CONTENT_POST_NOT_FOUND => 'post not found',
            self::CONTENT_POST_DELETED => 'post has been deleted',
            self::CONTENT_COMMENT_NOT_FOUND => 'comment not found',
            self::CONTENT_UNDER_REVIEW => 'content is under review',
            self::SOCIAL_CANNOT_FOLLOW_SELF => 'cannot follow yourself',
            self::SOCIAL_ALREADY_FOLLOWED => 'already followed',
            self::SOCIAL_USER_BLOCKED => 'user has blocked interaction',
            self::SOCIAL_CANNOT_BLOCK_SELF => 'cannot block yourself',
            self::CHAT_CONVERSATION_NOT_FOUND => 'conversation not found',
            self::CHAT_NOT_A_MEMBER => 'user is not a member of conversation',
            self::CHAT_FORBIDDEN_BY_PRIVACY => 'chat cannot be initiated due to privacy or block status',
            self::MESSAGE_NOT_FOUND => 'message not found',
            self::MESSAGE_DUPLICATE_CLIENT_ID => 'duplicate client message id',
            self::MESSAGE_SEND_FAILED => 'failed to send message',
            self::RISK_ACTION_DENIED => 'operation denied by risk control',
            self::RISK_CHALLENGE_REQUIRED => 'verification challenge required',
            self::MODERATION_REJECTED => 'content rejected by moderation',
            self::INTERNAL_SERVER_ERROR => 'internal server error',
            self::INVALID_ARGUMENT => 'invalid request arguments',
            self::RPC_TIMEOUT => 'upstream service rpc timeout',
            self::RATE_LIMIT_EXCEEDED => 'rate limit exceeded, please slow down',
            self::IDEMPOTENCY_CONFLICT => 'concurrent or conflicting idempotent request in progress',
            self::SERVICE_UNAVAILABLE => 'service temporarily unavailable',
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::OK => 200,
            self::AUTH_UNAUTHORIZED,
            self::AUTH_INVALID_CREDENTIALS,
            self::AUTH_TOKEN_EXPIRED,
            self::AUTH_TOKEN_INVALID,
            self::AUTH_TOKEN_REVOKED,
            self::AUTH_SOCIAL_CODE_INVALID,
            self::AUTH_SOCIAL_BIND_TOKEN_INVALID,
            self::AUTH_SMS_CODE_INVALID,
            self::AUTH_SMS_CODE_EXPIRED => 401,
            self::PERSONA_FORBIDDEN,
            self::CHAT_FORBIDDEN_BY_PRIVACY,
            self::SOCIAL_USER_BLOCKED => 403,
            self::USER_NOT_FOUND, self::PERSONA_NOT_FOUND, self::CONTENT_POST_NOT_FOUND, self::CONTENT_COMMENT_NOT_FOUND, self::CHAT_CONVERSATION_NOT_FOUND, self::MESSAGE_NOT_FOUND => 404,
            self::USER_ALREADY_EXISTS,
            self::SOCIAL_ALREADY_FOLLOWED,
            self::IDEMPOTENCY_CONFLICT,
            self::MESSAGE_DUPLICATE_CLIENT_ID,
            self::AUTH_SOCIAL_PROVIDER_ALREADY_BOUND,
            self::AUTH_SOCIAL_BIND_CONFLICT,
            self::AUTH_SOCIAL_BIND_PHONE_CHANGED => 409,
            self::AUTH_SOCIAL_PROVIDER_UNSUPPORTED,
            self::INVALID_ARGUMENT,
            self::AUTH_DEVICE_INVALID,
            self::AUTH_TICKET_EXPIRED,
            self::SOCIAL_CANNOT_FOLLOW_SELF,
            self::SOCIAL_CANNOT_BLOCK_SELF,
            self::PERSONA_LIMIT_EXCEEDED => 422,
            self::RATE_LIMIT_EXCEEDED => 429,
            self::AUTH_SOCIAL_UPSTREAM_ERROR, self::SERVICE_UNAVAILABLE, self::RPC_TIMEOUT => 503,
            default => 500,
        };
    }
}
