<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

/** Version-independent reason for applying durability damage to an item. */
enum ItemDamageCause: string
{
    case BLOCK_BREAK = 'block_break';
    case ENTITY_ATTACK = 'entity_attack';
    case DAMAGE_ABSORPTION = 'damage_absorption';
    case ITEM_USE = 'item_use';
    case CUSTOM = 'custom';
}
