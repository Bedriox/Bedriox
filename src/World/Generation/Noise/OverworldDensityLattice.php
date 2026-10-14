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

/**
 * A chunk-sized coarse density lattice with deterministic trilinear expansion.
 *
 * Sampling the expensive density graph only at shared cell corners keeps chunk
 * generation bounded while preserving identical values on neighboring borders.
 */
final class OverworldDensityLattice
{
    private const int CHUNK_WIDTH = 16;

    /** @var list<float> */
    private array $samples = [];
    private readonly int $xSampleCount;
    private readonly int $ySampleCount;
    private readonly int $zSampleCount;

    public function __construct(
        OverworldBaseDensitySampler $density,
        ?OverworldCavifier $cavifier,
        private readonly int $originX,
        private readonly int $originZ,
        private readonly int $minimumY,
        private readonly int $maximumY,
        private readonly int $horizontalStep = 4,
        private readonly int $verticalStep = 8,
    ) {
        if ($minimumY >= $maximumY
            || $horizontalStep < 1
            || $verticalStep < 1
            || self::CHUNK_WIDTH % $horizontalStep !== 0
            || ($maximumY - $minimumY) % $verticalStep !== 0) {
            throw new \InvalidArgumentException('Density lattice bounds must align to positive cell steps.');
        }

        $this->xSampleCount = intdiv(self::CHUNK_WIDTH, $horizontalStep) + 1;
        $this->ySampleCount = intdiv($maximumY - $minimumY, $verticalStep) + 1;
        $this->zSampleCount = $this->xSampleCount;

        for ($sampleX = 0; $sampleX < $this->xSampleCount; ++$sampleX) {
            $x = $originX + $sampleX * $horizontalStep;
            for ($sampleZ = 0; $sampleZ < $this->zSampleCount; ++$sampleZ) {
                $z = $originZ + $sampleZ * $horizontalStep;
                $column = $density->sampleColumn($x, $z, $minimumY, $maximumY, $verticalStep);
                $surface = $cavifier === null
                    ? 0
                    : self::surfaceFromColumn($column, $minimumY, $maximumY, $verticalStep);
                $finalColumn = $cavifier === null
                    ? $column
                    : $cavifier->cavifyColumn($x, $z, $minimumY, $verticalStep, $column, $surface);
                foreach ($finalColumn as $value) {
                    $this->samples[] = $value;
                }
            }
        }
    }

    public function densityAt(int $x, int $y, int $z): float
    {
        if ($x < $this->originX || $x > $this->originX + self::CHUNK_WIDTH
            || $z < $this->originZ || $z > $this->originZ + self::CHUNK_WIDTH
            || $y < $this->minimumY || $y > $this->maximumY) {
            throw new \OutOfBoundsException('Density coordinate lies outside this chunk lattice.');
        }

        $localX = $x - $this->originX;
        $localY = $y - $this->minimumY;
        $localZ = $z - $this->originZ;
        $cellX = min($this->xSampleCount - 2, intdiv($localX, $this->horizontalStep));
        $cellY = min($this->ySampleCount - 2, intdiv($localY, $this->verticalStep));
        $cellZ = min($this->zSampleCount - 2, intdiv($localZ, $this->horizontalStep));
        $amountX = ($localX - $cellX * $this->horizontalStep) / $this->horizontalStep;
        $amountY = ($localY - $cellY * $this->verticalStep) / $this->verticalStep;
        $amountZ = ($localZ - $cellZ * $this->horizontalStep) / $this->horizontalStep;

        $lowerNorth = self::lerp(
            $amountX,
            $this->sampleAt($cellX, $cellY, $cellZ),
            $this->sampleAt($cellX + 1, $cellY, $cellZ),
        );
        $lowerSouth = self::lerp(
            $amountX,
            $this->sampleAt($cellX, $cellY, $cellZ + 1),
            $this->sampleAt($cellX + 1, $cellY, $cellZ + 1),
        );
        $upperNorth = self::lerp(
            $amountX,
            $this->sampleAt($cellX, $cellY + 1, $cellZ),
            $this->sampleAt($cellX + 1, $cellY + 1, $cellZ),
        );
        $upperSouth = self::lerp(
            $amountX,
            $this->sampleAt($cellX, $cellY + 1, $cellZ + 1),
            $this->sampleAt($cellX + 1, $cellY + 1, $cellZ + 1),
        );

        return self::lerp(
            $amountY,
            self::lerp($amountZ, $lowerNorth, $lowerSouth),
            self::lerp($amountZ, $upperNorth, $upperSouth),
        );
    }

    public function surfaceHeight(int $x, int $z): int
    {
        $densities = $this->densitiesForColumn($x, $z);
        for ($index = count($densities) - 2; $index >= 0; --$index) {
            if ($densities[$index] > 0.0) {
                return $this->minimumY + $index;
            }
        }

        return $this->minimumY;
    }

    public function minimumY(): int
    {
        return $this->minimumY;
    }

    public function maximumY(): int
    {
        return $this->maximumY;
    }

    /** @return non-empty-list<int> X + Z * 16 ordering */
    public function surfaceHeightsForChunk(): array
    {
        $heights = array_fill(0, self::CHUNK_WIDTH * self::CHUNK_WIDTH, $this->minimumY);
        $topCellY = $this->ySampleCount - 2;
        for ($localZ = 0; $localZ < self::CHUNK_WIDTH; ++$localZ) {
            $cellZ = min($this->zSampleCount - 2, intdiv($localZ, $this->horizontalStep));
            $amountZ = ($localZ - $cellZ * $this->horizontalStep) / $this->horizontalStep;
            for ($localX = 0; $localX < self::CHUNK_WIDTH; ++$localX) {
                $cellX = min($this->xSampleCount - 2, intdiv($localX, $this->horizontalStep));
                $amountX = ($localX - $cellX * $this->horizontalStep) / $this->horizontalStep;
                $northWestBase = ($cellX * $this->zSampleCount + $cellZ) * $this->ySampleCount;
                $northEastBase = (($cellX + 1) * $this->zSampleCount + $cellZ) * $this->ySampleCount;
                $southWestBase = ($cellX * $this->zSampleCount + $cellZ + 1) * $this->ySampleCount;
                $southEastBase = (($cellX + 1) * $this->zSampleCount + $cellZ + 1) * $this->ySampleCount;
                $upperIndex = $topCellY + 1;
                $upperNorthWest = $this->samples[$northWestBase + $upperIndex];
                $upperNorth = $upperNorthWest
                    + $amountX * ($this->samples[$northEastBase + $upperIndex] - $upperNorthWest);
                $upperSouthWest = $this->samples[$southWestBase + $upperIndex];
                $upperSouth = $upperSouthWest
                    + $amountX * ($this->samples[$southEastBase + $upperIndex] - $upperSouthWest);
                $upper = $upperNorth + $amountZ * ($upperSouth - $upperNorth);
                for ($cellY = $topCellY; $cellY >= 0; --$cellY) {
                    $lowerNorthWest = $this->samples[$northWestBase + $cellY];
                    $lowerNorth = $lowerNorthWest
                        + $amountX * ($this->samples[$northEastBase + $cellY] - $lowerNorthWest);
                    $lowerSouthWest = $this->samples[$southWestBase + $cellY];
                    $lowerSouth = $lowerSouthWest
                        + $amountX * ($this->samples[$southEastBase + $cellY] - $lowerSouthWest);
                    $lower = $lowerNorth + $amountZ * ($lowerSouth - $lowerNorth);
                    if ($lower > 0.0) {
                        for ($offset = $this->verticalStep - 1; $offset >= 0; --$offset) {
                            if (self::lerp($offset / $this->verticalStep, $lower, $upper) > 0.0) {
                                $heights[$localX + $localZ * self::CHUNK_WIDTH] = min(
                                    $this->maximumY - 1,
                                    $this->minimumY + $cellY * $this->verticalStep + $offset,
                                );
                                break 2;
                            }
                        }
                    }
                    $upper = $lower;
                }
            }
        }

        return array_values($heights);
    }

    /** @return list<float> one interpolated value for every inclusive Y coordinate */
    public function densitiesForColumn(int $x, int $z): array
    {
        if ($x < $this->originX || $x > $this->originX + self::CHUNK_WIDTH
            || $z < $this->originZ || $z > $this->originZ + self::CHUNK_WIDTH) {
            throw new \OutOfBoundsException('Density column lies outside this chunk lattice.');
        }

        $localX = $x - $this->originX;
        $localZ = $z - $this->originZ;
        $cellX = min($this->xSampleCount - 2, intdiv($localX, $this->horizontalStep));
        $cellZ = min($this->zSampleCount - 2, intdiv($localZ, $this->horizontalStep));
        $amountX = ($localX - $cellX * $this->horizontalStep) / $this->horizontalStep;
        $amountZ = ($localZ - $cellZ * $this->horizontalStep) / $this->horizontalStep;
        $densities = [];
        $upper = 0.0;
        for ($cellY = 0; $cellY < $this->ySampleCount - 1; ++$cellY) {
            $lowerNorthWest = $this->sampleAt($cellX, $cellY, $cellZ);
            $lowerNorth = $lowerNorthWest
                + $amountX * ($this->sampleAt($cellX + 1, $cellY, $cellZ) - $lowerNorthWest);
            $lowerSouthWest = $this->sampleAt($cellX, $cellY, $cellZ + 1);
            $lowerSouth = $lowerSouthWest
                + $amountX * ($this->sampleAt($cellX + 1, $cellY, $cellZ + 1) - $lowerSouthWest);
            $lower = $lowerNorth + $amountZ * ($lowerSouth - $lowerNorth);
            $upperNorthWest = $this->sampleAt($cellX, $cellY + 1, $cellZ);
            $upperNorth = $upperNorthWest
                + $amountX * ($this->sampleAt($cellX + 1, $cellY + 1, $cellZ) - $upperNorthWest);
            $upperSouthWest = $this->sampleAt($cellX, $cellY + 1, $cellZ + 1);
            $upperSouth = $upperSouthWest
                + $amountX * ($this->sampleAt($cellX + 1, $cellY + 1, $cellZ + 1) - $upperSouthWest);
            $upper = $upperNorth + $amountZ * ($upperSouth - $upperNorth);
            $increment = ($upper - $lower) / $this->verticalStep;
            for ($offsetY = 0; $offsetY < $this->verticalStep; ++$offsetY) {
                $densities[] = $lower + $increment * $offsetY;
            }
        }
        $densities[] = $upper;

        return $densities;
    }

    /** @return array<int, list<float>> X + Z * 16 ordering */
    public function densitiesForChunk(): array
    {
        $columns = array_fill(0, self::CHUNK_WIDTH * self::CHUNK_WIDTH, []);
        $lastCellY = $this->ySampleCount - 2;
        for ($cellY = 0; $cellY <= $lastCellY; ++$cellY) {
            for ($cellZ = 0; $cellZ < $this->zSampleCount - 1; ++$cellZ) {
                $baseZ = $cellZ * $this->horizontalStep;
                for ($cellX = 0; $cellX < $this->xSampleCount - 1; ++$cellX) {
                    $baseX = $cellX * $this->horizontalStep;
                    $lowerNorthWest = $this->samples[$this->index($cellX, $cellY, $cellZ)];
                    $lowerNorthEast = $this->samples[$this->index($cellX + 1, $cellY, $cellZ)];
                    $lowerSouthWest = $this->samples[$this->index($cellX, $cellY, $cellZ + 1)];
                    $lowerSouthEast = $this->samples[$this->index($cellX + 1, $cellY, $cellZ + 1)];
                    $upperNorthWest = $this->samples[$this->index($cellX, $cellY + 1, $cellZ)];
                    $upperNorthEast = $this->samples[$this->index($cellX + 1, $cellY + 1, $cellZ)];
                    $upperSouthWest = $this->samples[$this->index($cellX, $cellY + 1, $cellZ + 1)];
                    $upperSouthEast = $this->samples[$this->index($cellX + 1, $cellY + 1, $cellZ + 1)];
                    for ($offsetZ = 0; $offsetZ < $this->horizontalStep; ++$offsetZ) {
                        $amountZ = $offsetZ / $this->horizontalStep;
                        $lowerWest = self::lerp($amountZ, $lowerNorthWest, $lowerSouthWest);
                        $lowerEast = self::lerp($amountZ, $lowerNorthEast, $lowerSouthEast);
                        $upperWest = self::lerp($amountZ, $upperNorthWest, $upperSouthWest);
                        $upperEast = self::lerp($amountZ, $upperNorthEast, $upperSouthEast);
                        $localZ = $baseZ + $offsetZ;
                        for ($offsetX = 0; $offsetX < $this->horizontalStep; ++$offsetX) {
                            $amountX = $offsetX / $this->horizontalStep;
                            $lower = self::lerp($amountX, $lowerWest, $lowerEast);
                            $upper = self::lerp($amountX, $upperWest, $upperEast);
                            $increment = ($upper - $lower) / $this->verticalStep;
                            $column = $baseX + $offsetX + $localZ * self::CHUNK_WIDTH;
                            for ($offsetY = 0; $offsetY < $this->verticalStep; ++$offsetY) {
                                $columns[$column][] = $lower + $increment * $offsetY;
                            }
                            if ($cellY === $lastCellY) {
                                $columns[$column][] = $upper;
                            }
                        }
                    }
                }
            }
        }

        return $columns;
    }

    /** @return list<float> */
    public function coarseSamples(): array
    {
        return $this->samples;
    }

    private function sampleAt(int $sampleX, int $sampleY, int $sampleZ): float
    {
        return $this->samples[$this->index($sampleX, $sampleY, $sampleZ)];
    }

    private function index(int $sampleX, int $sampleY, int $sampleZ): int
    {
        return ($sampleX * $this->zSampleCount + $sampleZ) * $this->ySampleCount + $sampleY;
    }

    private static function lerp(float $amount, float $start, float $end): float
    {
        return $start + $amount * ($end - $start);
    }

    /** @param list<float> $column */
    private static function surfaceFromColumn(array $column, int $minimumY, int $maximumY, int $step): int
    {
        $upperDensity = $column[count($column) - 1];
        for ($index = count($column) - 2; $index >= 0; --$index) {
            $lowerDensity = $column[$index];
            if ($lowerDensity > 0.0) {
                for ($offset = $step - 1; $offset >= 0; --$offset) {
                    $density = self::lerp($offset / $step, $lowerDensity, $upperDensity);
                    if ($density > 0.0) {
                        return min($maximumY - 1, $minimumY + $index * $step + $offset);
                    }
                }
            }
            $upperDensity = $lowerDensity;
        }

        return $minimumY;
    }
}
