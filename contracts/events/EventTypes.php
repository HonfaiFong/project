<?php

declare(strict_types=1);

namespace Contracts\Events;

final class EventTypes
{
    public const USER_REGISTERED = 'user.registered.v1';
    public const USER_UPDATED    = 'user.updated.v1';
    public const PERSONA_UPDATED = 'persona.updated.v1';

    public const POST_CREATED    = 'post.created.v1';
    public const POST_APPROVED   = 'post.approved.v1';
    public const POST_DELETED    = 'post.deleted.v1';
    public const COMMENT_CREATED = 'comment.created.v1';

    public const LIKE_CREATED   = 'like.created.v1';
    public const FOLLOW_CREATED = 'follow.created.v1';

    public const CONVERSATION_CREATED = 'conversation.created.v1';

    public const MESSAGE_CREATED   = 'message.created.v1';
    public const MESSAGE_DELIVERED = 'message.delivered.v1';
    public const MESSAGE_READ      = 'message.read.v1';

    public const REPORT_CREATED         = 'report.created.v1';
    public const MODERATION_COMPLETED   = 'moderation.completed.v1';

    public const NOTIFICATION_REQUESTED = 'notification.requested.v1';

    public const ANALYTICS_EVENT        = 'analytics.event.v1';
}
