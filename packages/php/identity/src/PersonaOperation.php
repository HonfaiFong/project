<?php

declare(strict_types=1);

namespace Packages\Identity;

/**
 * Operations which may be performed through a selected persona.
 *
 * The enum is deliberately closed. Callers must send a known value and the
 * default/unknown value is denied by the user service.
 */
enum PersonaOperation: int
{
    case UNSPECIFIED = 0;
    case CREATE_POST = 1;
    case CREATE_COMMENT = 2;
    case CREATE_CONVERSATION = 3;
    case SEND_MESSAGE = 4;
    case LIKE = 10;
    case UNLIKE = 11;
    case FOLLOW = 12;
    case UNFOLLOW = 13;
    case BLOCK = 14;
    case UNBLOCK = 15;
    case REPORT = 16;
    case CONNECT = 17;
    case UPDATE_PROFILE = 18;

    public function allowsAlias(): bool
    {
        return match ($this) {
            self::CREATE_POST,
            self::CREATE_COMMENT,
            self::CREATE_CONVERSATION,
            self::SEND_MESSAGE => true,
            default => false,
        };
    }

    public function requiresMain(): bool
    {
        return !$this->allowsAlias() && $this !== self::UNSPECIFIED;
    }
}
