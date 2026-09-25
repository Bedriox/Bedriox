<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

/** Stable storage-container identities exposed to plugins. */
enum ContainerType: string
{
    /** Plugin-owned inventory with no backing block or implicit persistence. */
    case VIRTUAL = 'virtual';
    case CHEST = 'chest';
    case DOUBLE_CHEST = 'double_chest';
    case TRAPPED_CHEST = 'trapped_chest';
    case DOUBLE_TRAPPED_CHEST = 'double_trapped_chest';
    case BARREL = 'barrel';
    case SHULKER_BOX = 'shulker_box';
    case ENDER_CHEST = 'ender_chest';
}
