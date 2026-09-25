<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

enum InventoryActionType: string
{
    case MOVE = 'move';
    case SWAP = 'swap';
    case SPLIT = 'split';
    case MERGE = 'merge';
    case DROP = 'drop';
    case DESTROY = 'destroy';
    case CREATIVE_CREATE = 'creative_create';
    case SLOT_CHANGE = 'slot_change';
}
