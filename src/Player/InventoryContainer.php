<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

enum InventoryContainer
{
    case Main;
    case Cursor;
    case Armor;
    case Offhand;
    /** Ephemeral request-local slot populated by an authoritative craft or creative selection. */
    case CreatedOutput;
}
