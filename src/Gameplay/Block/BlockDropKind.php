<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Block;

/** Internal strategies for the initially supported vanilla block drops. */
enum BlockDropKind
{
    case None;
    case Self;
    case Dirt;
    case Cobblestone;
    case CobbledDeepslate;
    case Gravel;
    case Coal;
    case RawCopper;
    case RawIron;
    case RawGold;
    case Redstone;
    case Diamond;
    case ClayBalls;
    case Snowballs;
    case Ice;
    case OakLeaves;
    case BirchLeaves;
    case SpruceLeaves;
}
