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

namespace Bedriox\Server\World;

use Bedriox\Api\World\WorldDimension;

/** Vanilla vertical section bounds used by generation, storage projection, and the Bedrock wire. */
final class WorldDimensionBounds
{
    private function __construct() {}

    public static function minimumSectionY(WorldDimension $dimension): int
    {
        return match ($dimension) {
            WorldDimension::OVERWORLD => -4,
            WorldDimension::NETHER, WorldDimension::END => 0,
        };
    }

    public static function maximumSectionY(WorldDimension $dimension): int
    {
        return match ($dimension) {
            WorldDimension::OVERWORLD => 19,
            WorldDimension::NETHER => 7,
            WorldDimension::END => 15,
        };
    }

    public static function minimumY(WorldDimension $dimension): int
    {
        return self::minimumSectionY($dimension) * 16;
    }

    public static function maximumY(WorldDimension $dimension): int
    {
        return (self::maximumSectionY($dimension) * 16) + 15;
    }
}
