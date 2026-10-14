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

namespace Bedriox\Server\World\Generation\Ecology;

use InvalidArgumentException;

/** Terrain and feature distances controlling one ecological placement point. */
final readonly class OverworldEcologyContext
{
    public function __construct(
        public int $x,
        public int $z,
        public int $baseDensityPermille,
        public int $slope,
        public int $waterDepth,
        public int $distanceToWater,
        public int $distanceToStructure,
        public int $distanceToCaveEntrance,
    ) {
        if ($baseDensityPermille < 0 || $baseDensityPermille > 1_000 || $slope < 0 || $slope > 64
            || $waterDepth < 0 || $waterDepth > 384
            || $distanceToWater < 0 || $distanceToWater > 4_096
            || $distanceToStructure < 0 || $distanceToStructure > 4_096
            || $distanceToCaveEntrance < 0 || $distanceToCaveEntrance > 4_096) {
            throw new InvalidArgumentException('Overworld ecology context is outside its accepted bounds.');
        }
    }
}
