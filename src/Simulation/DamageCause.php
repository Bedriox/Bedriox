<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

enum DamageCause: string
{
    case Attack = 'attack';
    case Fall = 'fall';
    case Kill = 'kill';
    case Plugin = 'plugin';
}
