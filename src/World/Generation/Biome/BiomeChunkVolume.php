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

namespace Bedriox\Server\World\Generation\Biome;

use Bedriox\Server\World\Biome;
use Bedriox\Server\World\BiomeStorage;
use Bedriox\Server\World\Chunk;
use Closure;
use InvalidArgumentException;

/** Builds one full-height biome volume from Bedrock's coarse sample buffer and Voronoi zoom. */
final readonly class BiomeChunkVolume
{
    public const int BUFFER_XZ_SIZE = 4;
    public const int BUFFER_Y_SIZE = 50;
    public const int BUFFER_LENGTH = self::BUFFER_XZ_SIZE * self::BUFFER_XZ_SIZE * self::BUFFER_Y_SIZE;
    private const int VORONOI_XZ_SIZE = 4;
    private const int VORONOI_Y_SIZE = 50;

    public function __construct(private int $levelSeed)
    {
        if ($levelSeed < 0 || $levelSeed > 0xffff_ffff) {
            throw new InvalidArgumentException('Biome-volume level seed must fit in 32 unsigned bits.');
        }
    }

    /**
     * @param Closure(int, int, int): Biome $sample nearest-target biome resolver at one coarse sample position
     * @return array<int, BiomeStorage>
     */
    public function storages(int $chunkX, int $chunkZ, Closure $sample): array
    {
        $originX = $chunkX * 16 - 8;
        $originY = Chunk::MIN_Y - 8;
        $originZ = $chunkZ * 16 - 8;
        $resolved = [];

        return $this->buildStorages(
            $chunkX,
            $chunkZ,
            static function (int $index) use (
                $sample,
                $originX,
                $originY,
                $originZ,
                &$resolved,
            ): Biome {
                $cached = $resolved[$index] ?? null;
                if ($cached instanceof Biome) {
                    return $cached;
                }
                $sampleX = intdiv($index, self::VORONOI_XZ_SIZE * self::VORONOI_Y_SIZE);
                $remainder = $index % (self::VORONOI_XZ_SIZE * self::VORONOI_Y_SIZE);
                $sampleZ = intdiv($remainder, self::VORONOI_Y_SIZE);
                $sampleY = $remainder % self::VORONOI_Y_SIZE;

                return $resolved[$index] = $sample(
                    $originX + $sampleX * 8,
                    $originY + $sampleY * 8,
                    $originZ + $sampleZ * 8,
                );
            },
        );
    }

    /**
     * Native BiomeSamplesContainer's production geometry is a four-by-four-by-50 grid in X-Z-Y
     * order. The 50 vertical samples cover the 384-block Overworld height plus one-cell borders.
     *
     * @param Closure(int, int, int): Biome $sample
     * @return list<Biome>
     */
    public function sampleBuffer(int $chunkX, int $chunkZ, Closure $sample): array
    {
        $originX = $chunkX * 16 - 8;
        $originY = Chunk::MIN_Y - 8;
        $originZ = $chunkZ * 16 - 8;
        $samples = [];
        for ($sampleX = 0; $sampleX < self::BUFFER_XZ_SIZE; ++$sampleX) {
            for ($sampleZ = 0; $sampleZ < self::BUFFER_XZ_SIZE; ++$sampleZ) {
                for ($sampleY = 0; $sampleY < self::BUFFER_Y_SIZE; ++$sampleY) {
                    $biome = $sample(
                        $originX + $sampleX * 8,
                        $originY + $sampleY * 8,
                        $originZ + $sampleZ * 8,
                    );
                    $samples[] = $biome;
                }
            }
        }

        return $samples;
    }

    /**
     * @param list<Biome> $samples native X-Z-Y ordered BiomeSamplesContainer contents
     * @return array<int, BiomeStorage>
     */
    public function storagesFromSampleBuffer(int $chunkX, int $chunkZ, array $samples): array
    {
        if (count($samples) !== self::BUFFER_LENGTH) {
            throw new InvalidArgumentException('Biome sample buffer must contain exactly 800 entries.');
        }
        return $this->buildStorages(
            $chunkX,
            $chunkZ,
            static fn(int $index): Biome => $samples[$index],
        );
    }

    /**
     * @param Closure(int): Biome $sampleAtIndex
     * @return array<int, BiomeStorage>
     */
    private function buildStorages(int $chunkX, int $chunkZ, Closure $sampleAtIndex): array
    {
        $originX = $chunkX * 16 - 8;
        $originY = Chunk::MIN_Y - 8;
        $originZ = $chunkZ * 16 - 8;
        $offsets = BiomeVoronoi::calculateOffsets(
            $this->levelSeed,
            $originX,
            $originY,
            $originZ,
            self::VORONOI_Y_SIZE,
        );
        /** @var array<int, bool> $blendingNeeds */
        $blendingNeeds = [];
        $storages = [];
        for ($sectionY = Chunk::MIN_SECTION_Y; $sectionY <= Chunk::MAX_SECTION_Y; ++$sectionY) {
            $palette = [];
            $paletteByIdentifier = [];
            $indices = str_repeat("\x00", BiomeStorage::BIOME_COUNT);
            for ($localX = 0; $localX < 16; ++$localX) {
                $worldX = $chunkX * 16 + $localX;
                for ($localZ = 0; $localZ < 16; ++$localZ) {
                    $worldZ = $chunkZ * 16 + $localZ;
                    for ($localY = 0; $localY < 16; ++$localY) {
                        $worldY = $sectionY * 16 + $localY;
                        $minimumCellIndex = self::minimumCellIndex(
                            $worldX,
                            $worldY,
                            $worldZ,
                            $originX,
                            $originY,
                            $originZ,
                        );
                        $needsBlending = $blendingNeeds[$minimumCellIndex] ?? null;
                        if (!is_bool($needsBlending)) {
                            $needsBlending = self::sampleNeedsBlending($minimumCellIndex, $sampleAtIndex);
                            $blendingNeeds[$minimumCellIndex] = $needsBlending;
                        }
                        $sampleIndex = $needsBlending
                            ? BiomeVoronoi::zoomedCellIndex(
                                $worldX,
                                $worldY,
                                $worldZ,
                                $offsets,
                                $minimumCellIndex,
                                self::VORONOI_XZ_SIZE,
                                self::VORONOI_Y_SIZE,
                            )
                            : $minimumCellIndex;
                        $biome = $sampleAtIndex($sampleIndex);
                        $paletteIndex = $paletteByIdentifier[$biome->identifier] ?? null;
                        if (!is_int($paletteIndex)) {
                            $paletteIndex = count($palette);
                            $palette[] = $biome;
                            $paletteByIdentifier[$biome->identifier] = $paletteIndex;
                        }
                        $indices[$localX + $localZ * 16 + $localY * 256] = chr($paletteIndex);
                    }
                }
            }
            $storages[$sectionY] = BiomeStorage::fromPaletteIndices($palette, $indices);
        }

        return $storages;
    }

    /** @param Closure(int): Biome $sampleAtIndex */
    private static function sampleNeedsBlending(int $index, Closure $sampleAtIndex): bool
    {
        $sampleX = intdiv($index, self::VORONOI_XZ_SIZE * self::VORONOI_Y_SIZE);
        $remainder = $index % (self::VORONOI_XZ_SIZE * self::VORONOI_Y_SIZE);
        $sampleZ = intdiv($remainder, self::VORONOI_Y_SIZE);
        $sampleY = $remainder % self::VORONOI_Y_SIZE;
        if ($sampleX === 0 || $sampleX === self::VORONOI_XZ_SIZE - 1
            || $sampleZ === 0 || $sampleZ === self::VORONOI_XZ_SIZE - 1
            || $sampleY === 0 || $sampleY === self::VORONOI_Y_SIZE - 1) {
            return true;
        }

        $identifier = $sampleAtIndex($index)->identifier;
        $nextX = self::VORONOI_XZ_SIZE * self::VORONOI_Y_SIZE;
        foreach ([
            1,
            self::VORONOI_Y_SIZE,
            self::VORONOI_Y_SIZE + 1,
            $nextX,
            $nextX + 1,
            $nextX + self::VORONOI_Y_SIZE,
            $nextX + self::VORONOI_Y_SIZE + 1,
        ] as $offset) {
            if ($sampleAtIndex($index + $offset)->identifier !== $identifier) {
                return true;
            }
        }

        return false;
    }

    private static function minimumCellIndex(
        int $worldX,
        int $worldY,
        int $worldZ,
        int $bufferOriginX,
        int $bufferOriginY,
        int $bufferOriginZ,
    ): int {
        $cellX = self::floorDivEight($worldX) - self::floorDivEight($bufferOriginX);
        $cellY = self::floorDivEight($worldY) - self::floorDivEight($bufferOriginY);
        $cellZ = self::floorDivEight($worldZ) - self::floorDivEight($bufferOriginZ);

        return $cellX * self::VORONOI_XZ_SIZE * self::VORONOI_Y_SIZE
            + $cellZ * self::VORONOI_Y_SIZE
            + $cellY;
    }

    private static function floorDivEight(int $value): int
    {
        return $value >= 0 ? intdiv($value, 8) : -intdiv(-$value + 7, 8);
    }
}
