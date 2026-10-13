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

use Bedriox\Server\World\Generation\Noise\SimpleRandom;
use Bedriox\Server\World\Generation\Noise\UnsignedLong;
use InvalidArgumentException;
use OutOfBoundsException;

final class BiomeVoronoi
{
    private const int XZ_SIZE = 4;
    private const int MAX_BUFFER_Y = 4096;
    private const float OFFSET_SCALE = 0.9;

    /** @return list<BiomeVoronoiOffset> */
    public static function calculateOffsets(
        int $levelSeed,
        int $bufferOriginX,
        int $bufferOriginY,
        int $bufferOriginZ,
        int $bufferSizeY,
    ): array {
        if ($levelSeed < 0 || $levelSeed > 0xffff_ffff) {
            throw new InvalidArgumentException('Biome Voronoi level seed must fit in 32 unsigned bits.');
        }
        if ($bufferSizeY < 1 || $bufferSizeY > self::MAX_BUFFER_Y) {
            throw new InvalidArgumentException('Biome Voronoi vertical buffer size is outside 1 through 4096.');
        }

        $originCellX = self::floorDivEight($bufferOriginX);
        $originCellY = self::floorDivEight($bufferOriginY);
        $originCellZ = self::floorDivEight($bufferOriginZ);
        $offsets = [];
        for ($x = 0; $x < self::XZ_SIZE; ++$x) {
            for ($z = 0; $z < self::XZ_SIZE; ++$z) {
                for ($y = 0; $y < $bufferSizeY; ++$y) {
                    $seed = self::positionalSeed($originCellX + $x, $originCellY + $y, $originCellZ + $z) ^ $levelSeed;
                    $random = new SimpleRandom($seed);
                    $offsets[] = new BiomeVoronoiOffset(
                        self::randomOffset($random),
                        self::randomOffset($random),
                        self::randomOffset($random),
                    );
                }
            }
        }

        return $offsets;
    }

    /** @param list<BiomeVoronoiOffset> $offsets */
    public static function zoomedCellIndex(
        int $positionX,
        int $positionY,
        int $positionZ,
        array $offsets,
        int $minimumCellIndex,
        int $bufferXZSize,
        int $bufferYSize,
    ): int {
        if ($bufferXZSize < 2 || $bufferYSize < 2 || $minimumCellIndex < 0) {
            throw new InvalidArgumentException('Biome Voronoi buffer geometry is invalid.');
        }

        $fractionX = self::floorModEight($positionX) / 8.0;
        $fractionY = self::floorModEight($positionY) / 8.0;
        $fractionZ = self::floorModEight($positionZ) / 8.0;
        $bestIndex = -1;
        $bestDistance = INF;
        // Native evaluates Z before Y within each X half and retains the earlier corner on a tie.
        foreach ([[0, 0, 0], [0, 1, 0], [0, 0, 1], [0, 1, 1],
            [1, 0, 0], [1, 1, 0], [1, 0, 1], [1, 1, 1]] as [$cornerX, $cornerZ, $cornerY]) {
            $index = $minimumCellIndex
                + ($cornerX * $bufferXZSize * $bufferYSize)
                + ($cornerZ * $bufferYSize)
                + $cornerY;
            $offset = $offsets[$index] ?? throw new OutOfBoundsException('Biome Voronoi offset buffer is incomplete.');
            $distanceX = $fractionX - $cornerX + $offset->x;
            $distanceY = $fractionY - $cornerY + $offset->y;
            $distanceZ = $fractionZ - $cornerZ + $offset->z;
            $distance = ($distanceX * $distanceX) + ($distanceY * $distanceY) + ($distanceZ * $distanceZ);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    private static function positionalSeed(int $x, int $y, int $z): int
    {
        $mixed = UnsignedLong::fromInt($x)->multiply(UnsignedLong::fromInt(3_129_871))
            ->xor(UnsignedLong::fromInt($z)->multiply(UnsignedLong::fromInt(116_129_781)))
            ->xor(UnsignedLong::fromInt($y));
        $hashed = $mixed->multiply($mixed)->multiply(UnsignedLong::fromInt(42_317_861))
            ->add($mixed->multiply(UnsignedLong::fromInt(11)))
            ->shiftRight(16);

        return (($hashed->high & 0xffff) << 32) | $hashed->low;
    }

    private static function randomOffset(SimpleRandom $random): float
    {
        $centered = SimpleRandom::float32($random->nextFloat() - 0.5);

        return SimpleRandom::float32($centered * SimpleRandom::float32(self::OFFSET_SCALE));
    }

    private static function floorDivEight(int $value): int
    {
        return $value >= 0 ? intdiv($value, 8) : -intdiv(-$value + 7, 8);
    }

    private static function floorModEight(int $value): int
    {
        $remainder = $value % 8;

        return $remainder < 0 ? $remainder + 8 : $remainder;
    }
}
