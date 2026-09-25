<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Inventory;

enum InventoryCloseReason: string
{
    case CLIENT = 'client';
    case PLUGIN = 'plugin';
    case SERVER = 'server';
    case REPLACED = 'replaced';
    case TELEPORT = 'teleport';
    case DEATH = 'death';
    case DISCONNECT = 'disconnect';
    case OUT_OF_RANGE = 'out_of_range';
    case BLOCK_REMOVED = 'block_removed';
    case CHUNK_UNLOAD = 'chunk_unload';
    case WORLD_UNLOAD = 'world_unload';
}
