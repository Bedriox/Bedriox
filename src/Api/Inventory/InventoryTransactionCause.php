<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

enum InventoryTransactionCause: string
{
    case PLAYER = 'player';
    case PLUGIN = 'plugin';
    case SERVER = 'server';
}
