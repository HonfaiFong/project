<?php

declare(strict_types=1);

namespace Services\SocialService\Domain\Entity;

/**
 * The only persona shape accepted by Social. UserService is the authority
 * that constructs this value, so Social never infers ownership from a body
 * id or from a row in another logical database.
 */
final readonly class MainPersona
{
    public function __construct(
        public string $id,
        public string $userId,
        public int $status = 1,
    ) {
    }
}
