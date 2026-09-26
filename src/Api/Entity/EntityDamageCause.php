<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

/** Stable causes shared by authoritative living-entity damage events. */
enum EntityDamageCause: string
{
    case ATTACK = 'attack';
    case FIRE = 'fire';
    case FIRE_TICK = 'fire_tick';
    case KILL = 'kill';
    case PLUGIN = 'plugin';
}
