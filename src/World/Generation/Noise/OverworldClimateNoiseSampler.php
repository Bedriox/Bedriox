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

/** Overworld climate channels sampled in quart coordinates. */
final readonly class OverworldClimateNoiseSampler
{
    private DoublePerlinNoise $offset;
    private DoublePerlinNoise $continentalness;
    private DoublePerlinNoise $weirdness;
    private DoublePerlinNoise $erosion;
    private DoublePerlinNoise $temperature;
    private DoublePerlinNoise $humidity;

    public function __construct(int $seed)
    {
        $factory = XoroshiroPositionalRandomFactory::fromSeed($seed);
        $this->offset = new DoublePerlinNoise($factory->forString('minecraft:offset'), -3, [1.0, 1.0, 1.0, 0.0]);
        $this->continentalness = new DoublePerlinNoise(
            $factory->forString('minecraft:continentalness'),
            -9,
            [1.0, 1.0, 2.0, 2.0, 2.0, 1.0, 1.0, 1.0, 1.0],
        );
        $this->weirdness = new DoublePerlinNoise($factory->forString('minecraft:ridge'), -7, [1.0, 2.0, 1.0, 0.0, 0.0, 0.0]);
        $this->erosion = new DoublePerlinNoise($factory->forString('minecraft:erosion'), -9, [1.0, 1.0, 0.0, 1.0, 1.0]);
        $this->temperature = new DoublePerlinNoise(
            $factory->forString('minecraft:temperature'),
            -10,
            [1.5, 0.0, 1.0, 0.0, 0.0, 0.0],
        );
        $this->humidity = new DoublePerlinNoise(
            $factory->forString('minecraft:vegetation'),
            -8,
            [1.0, 1.0, 0.0, 0.0, 0.0, 0.0],
        );
    }

    public function sample(int $quartX, int $quartZ): OverworldClimateNoiseSample
    {
        $x = (float) $quartX;
        $z = (float) $quartZ;
        $shiftedX = $x + $this->offset->sample($x, 0.0, $z) * 4.0;
        $shiftedZ = $z + $this->offset->sample($z, $x, 0.0) * 4.0;

        return new OverworldClimateNoiseSample(
            $shiftedX,
            $shiftedZ,
            $this->continentalness->sample($shiftedX, 0.0, $shiftedZ),
            $this->weirdness->sample($shiftedX, 0.0, $shiftedZ),
            $this->erosion->sample($shiftedX, 0.0, $shiftedZ),
            $this->temperature->sample($shiftedX, 0.0, $shiftedZ),
            $this->humidity->sample($shiftedX, 0.0, $shiftedZ),
        );
    }
}
