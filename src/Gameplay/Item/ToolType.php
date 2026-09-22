<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Item;

/** Stable gameplay tool families, independent of Bedrock wire values. */
enum ToolType
{
    case Pickaxe;
    case Axe;
    case Shovel;
    case Hoe;
    case Sword;
    case Shears;
}
