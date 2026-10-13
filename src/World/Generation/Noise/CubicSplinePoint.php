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

final readonly class CubicSplinePoint
{
    public function __construct(
        public float $location,
        public float|CubicSpline $value,
        public float $derivative,
    ) {}

    public function evaluate(TerrainClimatePoint $point): float
    {
        return $this->value instanceof CubicSpline ? $this->value->evaluate($point) : $this->value;
    }
}
