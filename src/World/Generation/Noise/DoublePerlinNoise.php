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

use InvalidArgumentException;

/** Pair of decorrelated Perlin stacks used by climate and density fields. */
final readonly class DoublePerlinNoise
{
    private const float INPUT_FACTOR = 1.0181268882175227;

    private OctavePerlinNoise $first;
    private OctavePerlinNoise $second;
    private float $valueFactor;

    /** @param list<float> $amplitudes */
    public function __construct(Xoroshiro128PlusPlus $random, int $firstOctave, array $amplitudes)
    {
        $active = [];
        foreach ($amplitudes as $index => $amplitude) {
            if ((float) $amplitude !== 0.0) {
                $active[] = $index;
            }
        }
        if ($active === []) {
            throw new InvalidArgumentException('Normal noise needs at least one active octave.');
        }
        $this->first = new OctavePerlinNoise($random, $firstOctave, $amplitudes);
        $this->second = new OctavePerlinNoise($random, $firstOctave, $amplitudes);
        $span = max($active) - min($active);
        $this->valueFactor = 1.0 / 6.0 / (0.1 * (1.0 + 1.0 / ($span + 1.0)));
    }

    public function sample(float $x, float $y, float $z): float
    {
        return ($this->first->sample($x, $y, $z) + $this->second->sample(
            $x * self::INPUT_FACTOR,
            $y * self::INPUT_FACTOR,
            $z * self::INPUT_FACTOR,
        )) * $this->valueFactor;
    }
}
