<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

enum DamageCause: string
{
    case Fall = 'fall';
    case Plugin = 'plugin';
}
