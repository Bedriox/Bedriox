<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

/** Stable gameplay meaning of an item activation; values are not Bedrock wire IDs. */
enum ItemUseKind: string
{
    case INSTANT = 'instant';
    case CONSUME = 'consume';
    case EQUIP = 'equip';
}
