<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

enum InventoryStackRequestActionType
{
    case Take;
    case Place;
    case Swap;
    case MineBlock;
}
