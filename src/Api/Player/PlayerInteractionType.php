<?php

declare(strict_types=1);

namespace Bedriox\Api\Player;

/** Version-independent player interaction kinds exposed to plugins. */
enum PlayerInteractionType: string
{
    case INTERACT = 'interact';
    case ATTACK = 'attack';
}
