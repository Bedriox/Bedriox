<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Navigation;

enum NavigationPathStatus: int
{
    case REACHED_TARGET = 0;
    case UNREACHABLE = 1;
    case NODE_BUDGET_EXHAUSTED = 2;
    case DEADLINE_EXCEEDED = 3;
    case PATH_LENGTH_EXCEEDED = 4;
}
