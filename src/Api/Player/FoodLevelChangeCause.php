<?php

declare(strict_types=1);

namespace Bedriox\Api\Player;

/** Stable cause of an authoritative nutrition-state change. */
enum FoodLevelChangeCause: string
{
    case CONSUMPTION = 'consumption';
    case EXHAUSTION = 'exhaustion';
    case REGENERATION = 'regeneration';
    case CUSTOM = 'custom';
}
