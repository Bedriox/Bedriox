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

namespace Bedriox\Server\World\Generation\Surface;

use InvalidArgumentException;

/** Final terrain measurements needed to classify one overworld surface column. */
final readonly class OverworldSurfaceContext
{
    public function __construct(
        public int $x,
        public int $z,
        public int $height,
        public int $seaLevel,
        public int $slope,
        public int $curvature,
        public int $waterDepth,
        public int $distanceToWater,
        public int $riverStrength,
        public int $temperature,
        public int $humidity,
    ) {
        if ($slope < 0 || $slope > 64 || $curvature < -64 || $curvature > 64
            || $waterDepth < 0 || $waterDepth > 384 || $distanceToWater < 0 || $distanceToWater > 4_096
            || $riverStrength < 0 || $riverStrength > 1_000
            || $temperature < -32_768 || $temperature > 32_767
            || $humidity < -32_768 || $humidity > 32_767) {
            throw new InvalidArgumentException('Overworld surface context is outside its accepted bounds.');
        }
    }
}
