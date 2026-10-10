<?php

declare(strict_types=1);

namespace Packages\Identity;

enum PersonaMode: int
{
    case UNSPECIFIED = 0;
    case MAIN = 1;
    case ALIAS = 2;
}
