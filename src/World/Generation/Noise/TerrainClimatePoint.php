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

namespace Bedriox\Server\World\Generation\Noise;

final readonly class TerrainClimatePoint
{
    public float $ridge;

    public float $foldedRidge;

    public function __construct(
        public float $continentalness,
        public float $erosion,
        public float $weirdness,
    ) {
        $this->ridge = $weirdness;
        $this->foldedRidge = -3.0 * (abs(abs($weirdness) - (2.0 / 3.0)) - (1.0 / 3.0));
    }
}
