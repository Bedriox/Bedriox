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
final class OverworldBaseDensitySampler
{
    private const float PRELIMINARY_SURFACE_THRESHOLD = 1.09375;
    private const int MAX_COLUMN_CACHE_ENTRIES = 512;
    private const int MAX_SURFACE_CACHE_ENTRIES = 4096;
    private const int MAX_SHAPE_CACHE_ENTRIES = 4096;
    private readonly OverworldClimateNoiseSampler $climate;
    private readonly OverworldTerrainShapeSampler $shape;
    private readonly BlendedTerrainNoise $terrain;
    private readonly DoublePerlinNoise $jagged;
    /** @var array<string, list<float>> */
    private array $columnCache = [];
    /** @var list<string> */
    private array $columnCacheOrder = [];
    /** @var array<string, int> */
    private array $preliminarySurfaceCache = [];
    /** @var list<string> */
    private array $preliminarySurfaceCacheOrder = [];
    /** @var array<string, TerrainShape> */
    private array $shapeCache = [];
    /** @var list<string> */
    private array $shapeCacheOrder = [];

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
        $cacheKey = $x . ':' . $z . ':' . $minimumY . ':' . $maximumY . ':' . $step;
        if (isset($this->columnCache[$cacheKey])) {
            return $this->columnCache[$cacheKey];
        }
        [$shape, $jagged] = $this->prepareColumn($x, $z);
        $terrainSamples = $this->terrain->sampleColumn($x, $z, $minimumY, $maximumY, $step);
        $samples = [];
        foreach ($terrainSamples as $offset => $terrainDensity) {
            $y = $minimumY + $offset * $step;
            $depth = 1.5 - (($y + 64.0) / 128.0) + $shape->offset + $jagged;
            $shaped = $depth * ($shape->factor / 128.0);
            if ($shaped < 0.0) {
                $shaped *= 0.25;
            }
            $samples[] = $shaped * 4.0 + $terrainDensity;
        }

        if (count($this->columnCacheOrder) === self::MAX_COLUMN_CACHE_ENTRIES) {
            $expired = array_shift($this->columnCacheOrder);
            unset($this->columnCache[$expired]);
        }
        $this->columnCacheOrder[] = $cacheKey;

        return $this->columnCache[$cacheKey] = $samples;
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

    public function interpolatedSurfaceHeight(
        int $x,
        int $z,
        int $originX,
        int $originZ,
        int $minimumY,
        int $maximumY,
        int $horizontalStep = 8,
        int $verticalStep = 8,
    ): int {
        $localX = $x - $originX;
        $localZ = $z - $originZ;
        if ($localX < 0 || $localX > 16 || $localZ < 0 || $localZ > 16
            || $horizontalStep < 1 || 16 % $horizontalStep !== 0
            || $verticalStep < 1 || ($maximumY - $minimumY) % $verticalStep !== 0) {
            throw new \InvalidArgumentException('Interpolated surface coordinates and steps must align to a chunk.');
        }
        $cellX = min(intdiv(16, $horizontalStep) - 1, intdiv($localX, $horizontalStep));
        $cellZ = min(intdiv(16, $horizontalStep) - 1, intdiv($localZ, $horizontalStep));
        $west = $originX + $cellX * $horizontalStep;
        $north = $originZ + $cellZ * $horizontalStep;
        $amountX = ($localX - $cellX * $horizontalStep) / $horizontalStep;
        $amountZ = ($localZ - $cellZ * $horizontalStep) / $horizontalStep;
        $northWest = $this->sampleColumn($west, $north, $minimumY, $maximumY, $verticalStep);
        $northEast = $this->sampleColumn($west + $horizontalStep, $north, $minimumY, $maximumY, $verticalStep);
        $southWest = $this->sampleColumn($west, $north + $horizontalStep, $minimumY, $maximumY, $verticalStep);
        $southEast = $this->sampleColumn(
            $west + $horizontalStep,
            $north + $horizontalStep,
            $minimumY,
            $maximumY,
            $verticalStep,
        );
        $sample = static fn(int $index): float => self::lerp(
            $amountZ,
            self::lerp($amountX, $northWest[$index], $northEast[$index]),
            self::lerp($amountX, $southWest[$index], $southEast[$index]),
        );
        $upper = $sample(count($northWest) - 1);
        for ($index = count($northWest) - 2; $index >= 0; --$index) {
            $lower = $sample($index);
            if ($lower > 0.0) {
                for ($offset = $verticalStep - 1; $offset >= 0; --$offset) {
                    if (self::lerp($offset / $verticalStep, $lower, $upper) > 0.0) {
                        return min($maximumY - 1, $minimumY + $index * $verticalStep + $offset);
                    }
                }
            }
            $upper = $lower;
        }

        return $minimumY;
    }

    /** Returns the eight-block-quantized surface used by aquifer fluid cells. */
    public function preliminarySurfaceLevel(int $x, int $z, int $minimumY, int $maximumY, int $step = 8): int
    {
        if ($minimumY > $maximumY || $step < 1) {
            throw new \InvalidArgumentException('Preliminary surface bounds and step must describe a non-empty column.');
        }
        $cacheKey = $x . ':' . $z . ':' . $minimumY . ':' . $maximumY . ':' . $step;
        if (isset($this->preliminarySurfaceCache[$cacheKey])) {
            return $this->preliminarySurfaceCache[$cacheKey];
        }
        $shape = $this->prepareShape($x, $z);
        $exclusiveBoundary = 128.0 * (
            1.5 + $shape->offset - self::PRELIMINARY_SURFACE_THRESHOLD * 32.0 / $shape->factor
        ) - 64.0;
        $largestAllowedY = (int) ceil($exclusiveBoundary) - 1;
        $stepsDown = $largestAllowedY >= $maximumY
            ? 0
            : intdiv($maximumY - $largestAllowedY + $step - 1, $step);

        $surface = max($minimumY, $maximumY - $stepsDown * $step);
        if (count($this->preliminarySurfaceCacheOrder) === self::MAX_SURFACE_CACHE_ENTRIES) {
            $expired = array_shift($this->preliminarySurfaceCacheOrder);
            unset($this->preliminarySurfaceCache[$expired]);
        }
        $this->preliminarySurfaceCacheOrder[] = $cacheKey;

        return $this->preliminarySurfaceCache[$cacheKey] = $surface;
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
        $quartX = self::floorDiv($x, 4);
        $quartZ = self::floorDiv($z, 4);
        $key = $quartX . ':' . $quartZ;
        $cached = $this->shapeCache[$key] ?? null;
        if ($cached instanceof TerrainShape) {
            return $cached;
        }
        $climate = $this->climate->sample($quartX, $quartZ);
        $shape = $this->shape->sample($climate->continentalness, $climate->erosion, $climate->weirdness);
        if (count($this->shapeCacheOrder) === self::MAX_SHAPE_CACHE_ENTRIES) {
            $expired = array_shift($this->shapeCacheOrder);
            unset($this->shapeCache[$expired]);
        }
        $this->shapeCacheOrder[] = $key;

        return $this->shapeCache[$key] = $shape;
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

    private static function lerp(float $amount, float $from, float $to): float
    {
        return $from + $amount * ($to - $from);
    }
}
