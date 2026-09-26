<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

enum EntityInteractionType: string
{
    case INTERACT = 'interact';
    case ITEM_INTERACT = 'item_interact';
}
