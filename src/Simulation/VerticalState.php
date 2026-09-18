<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

enum VerticalState: string
{
    case GROUNDED = 'grounded';
    case AIRBORNE = 'airborne';
}
