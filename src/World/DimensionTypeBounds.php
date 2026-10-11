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

use Bedriox\Api\World\DimensionType;

/** Vanilla vertical section bounds used by generation, storage projection, and the Bedrock wire. */
final class DimensionTypeBounds
{
    private function __construct() {}

    public static function minimumSectionY(DimensionType $dimension): int
    {
        return match ($dimension) {
            DimensionType::OVERWORLD => -4,
            DimensionType::NETHER, DimensionType::END => 0,
        };
    }

    public static function maximumSectionY(DimensionType $dimension): int
    {
        return match ($dimension) {
            DimensionType::OVERWORLD => 19,
            DimensionType::NETHER => 7,
            DimensionType::END => 15,
        };
    }

    public static function minimumY(DimensionType $dimension): int
    {
        return self::minimumSectionY($dimension) * 16;
    }

    public static function maximumY(DimensionType $dimension): int
    {
        return (self::maximumSectionY($dimension) * 16) + 15;
    }
}
