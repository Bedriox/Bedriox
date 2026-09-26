<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Spawn\Natural;

enum NaturalSpawnMedium: string
{
    case GROUND = 'ground';
    case WATER = 'water';
    case AIR = 'air';
    case LAVA = 'lava';
}
