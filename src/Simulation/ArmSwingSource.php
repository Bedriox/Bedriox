<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

/** Version-independent reason for an authoritative arm-swing presentation. */
enum ArmSwingSource: string
{
    case Missed = 'interact';
    case Mining = 'mine';
    case Attack = 'attack';
    case Plugin = 'event';
}
