<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

enum EntityCategory: string
{
    case ANIMAL = 'animal';
    case MONSTER = 'monster';
    case AMBIENT = 'ambient';
    case WATER = 'water';
    case FLYING = 'flying';
    case VILLAGER = 'villager';
    case MISCELLANEOUS = 'miscellaneous';
}
