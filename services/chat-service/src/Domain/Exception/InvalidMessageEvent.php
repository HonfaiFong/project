<?php

declare(strict_types=1);

namespace Services\ChatService\Domain\Exception;

/** Invalid event data must be quarantined instead of retried as a dependency outage. */
final class InvalidMessageEvent extends \RuntimeException
{
}
