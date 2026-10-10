<?php

declare(strict_types=1);

namespace Services\UserService\Domain\Entity;

enum PersonaType: int
{
    case MAIN = 1;
    case ALIAS = 2;
}
