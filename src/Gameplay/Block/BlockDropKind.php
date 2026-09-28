<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

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
