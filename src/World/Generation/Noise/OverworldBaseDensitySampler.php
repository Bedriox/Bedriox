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

/** Samples the continuous Overworld terrain field before caves and aquifers are applied. */
final readonly class OverworldBaseDensitySampler
{
    private const float PRELIMINARY_SURFACE_THRESHOLD = 1.09375;
    private OverworldClimateNoiseSampler $climate;
    private OverworldTerrainShapeSampler $shape;
    private BlendedTerrainNoise $terrain;
    private DoublePerlinNoise $jagged;

    public function __construct(int $seed)
    {
        $factory = XoroshiroPositionalRandomFactory::fromSeed($seed);
        $this->climate = new OverworldClimateNoiseSampler($seed);
        $this->shape = new OverworldTerrainShapeSampler();
        $this->terrain = new BlendedTerrainNoise($factory->forString('minecraft:terrain'));
        $this->jagged = new DoublePerlinNoise($factory->forString('minecraft:jagged'), -16, array_fill(0, 16, 1.0));
    }

    public function sample(int $x, int $y, int $z): float
    {
        [$shape, $jagged] = $this->prepareColumn($x, $z);

        return $this->sampleWithShape($x, $y, $z, $shape, $jagged);
    }

    /** @return list<float> */
    public function sampleColumn(int $x, int $z, int $minimumY, int $maximumY, int $step): array
    {
        if ($minimumY > $maximumY || $step < 1) {
            throw new \InvalidArgumentException('Density column bounds and step must describe a non-empty column.');
        }
        [$shape, $jagged] = $this->prepareColumn($x, $z);
        $samples = [];
        for ($y = $minimumY; $y <= $maximumY; $y += $step) {
            $samples[] = $this->sampleWithShape($x, $y, $z, $shape, $jagged);
        }

        return $samples;
    }

    public function surfaceHeight(int $x, int $z, int $minimumY, int $maximumY, int $step = 8): int
    {
        if ($minimumY >= $maximumY || $step < 1) {
            throw new \InvalidArgumentException('Density surface bounds and step must describe a non-empty column.');
        }
        [$shape, $jagged] = $this->prepareColumn($x, $z);
        $upperDensity = $this->sampleWithShape($x, $maximumY, $z, $shape, $jagged);
        for ($y = $maximumY - $step; $y >= $minimumY; $y -= $step) {
            $lowerDensity = $this->sampleWithShape($x, $y, $z, $shape, $jagged);
            if ($lowerDensity > 0.0) {
                for ($offset = $step - 1; $offset >= 0; --$offset) {
                    $density = $lowerDensity + ($upperDensity - $lowerDensity) * ($offset / $step);
                    if ($density > 0.0) {
                        return min($maximumY - 1, $y + $offset);
                    }
                }
            }
            $upperDensity = $lowerDensity;
        }

        return $minimumY;
    }

    /** Returns the eight-block-quantized surface used by aquifer fluid cells. */
    public function preliminarySurfaceLevel(int $x, int $z, int $minimumY, int $maximumY, int $step = 8): int
    {
        if ($minimumY > $maximumY || $step < 1) {
            throw new \InvalidArgumentException('Preliminary surface bounds and step must describe a non-empty column.');
        }
        $shape = $this->prepareShape($x, $z);
        $exclusiveBoundary = 128.0 * (
            1.5 + $shape->offset - self::PRELIMINARY_SURFACE_THRESHOLD * 32.0 / $shape->factor
        ) - 64.0;
        $largestAllowedY = (int) ceil($exclusiveBoundary) - 1;
        $stepsDown = $largestAllowedY >= $maximumY
            ? 0
            : intdiv($maximumY - $largestAllowedY + $step - 1, $step);

        return max($minimumY, $maximumY - $stepsDown * $step);
    }

    /** Returns the gradient-only aquifer surface field; this is not the full terrain density. */
    public function initialDensityWithoutJaggedness(int $x, int $y, int $z): float
    {
        $shape = $this->prepareShape($x, $z);
        $depth = 1.5 - (($y + 64.0) / 128.0) + $shape->offset;
        $shaped = $depth * ($shape->factor / 128.0);

        return ($shaped < 0.0 ? $shaped * 0.25 : $shaped) * 4.0;
    }

    /** @return array{TerrainShape, float} */
    private function prepareColumn(int $x, int $z): array
    {
        $shape = $this->prepareShape($x, $z);

        return [$shape, $this->jaggedContribution($x, $z, $shape)];
    }

    private function prepareShape(int $x, int $z): TerrainShape
    {
        $climate = $this->climate->sample(self::floorDiv($x, 4), self::floorDiv($z, 4));

        return $this->shape->sample($climate->continentalness, $climate->erosion, $climate->weirdness);
    }

    private function jaggedContribution(int $x, int $z, TerrainShape $shape): float
    {
        if ($shape->jaggedness <= 0.0) {
            return 0.0;
        }
        $jagged = $this->jagged->sample($x * 1_500.0, 0.0, $z * 1_500.0);

        return $shape->jaggedness * ($jagged < 0.0 ? $jagged * 0.5 : $jagged);
    }

    private function sampleWithShape(int $x, int $y, int $z, TerrainShape $shape, float $jagged): float
    {
        $depth = 1.5 - (($y + 64.0) / 128.0) + $shape->offset;
        $depth += $jagged;
        $shaped = $depth * ($shape->factor / 128.0);
        if ($shaped < 0.0) {
            $shaped *= 0.25;
        }

        return $shaped * 4.0 + $this->terrain->sample($x, $y, $z);
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
