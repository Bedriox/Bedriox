<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Item;

/** Stable server-owned armor slot order used by persistence and gameplay. */
enum ArmorSlot: int
{
    case Head = 0;
    case Chest = 1;
    case Legs = 2;
    case Feet = 3;
}
