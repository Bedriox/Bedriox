<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

enum NutritionChangeReason
{
    case CONSUMPTION;
    case EXHAUSTION;
    case NATURAL_REGENERATION;
    case RESPAWN;
}
