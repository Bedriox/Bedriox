<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

enum BlockBreakAction
{
    case Start;
    case Abort;
    case Complete;
}
