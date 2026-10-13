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
 * Chunk-local modern Overworld aquifer sampler.
 *
 * Cell locations, preliminary surfaces, fluid levels and pressure composition
 * are deliberately kept together so their caches cannot leak between chunks.
 */
final class OverworldAquifer
{
    private const int GLOBAL_WATER_LEVEL = 63;
    private const int GLOBAL_LAVA_LEVEL = -54;
    private const int DRY_LEVEL = -4096;
    /** @var list<array{int, int}> */
    private const array SURFACE_OFFSETS = [
        [0, 0], [-2, -1], [-1, -1], [0, -1], [1, -1], [-3, 0], [-2, 0],
        [-1, 0], [1, 0], [-2, 1], [-1, 1], [0, 1], [1, 1],
    ];

    private readonly XoroshiroPositionalRandomFactory $centers;
    private readonly DoublePerlinNoise $barrier;
    private readonly DoublePerlinNoise $floodedness;
    private readonly DoublePerlinNoise $spread;
    private readonly DoublePerlinNoise $lava;
    /** @var array<string, array{x: int, y: int, z: int}> */
    private array $centerCache = [];
    /** @var array<string, list<array{x: int, y: int, z: int}>> */
    private array $neighborhoodCache = [];
    /** @var array<string, array{level: int, material: OverworldAquiferMaterial}> */
    private array $statusCache = [];
    /** @var array<string, int> */
    private array $surfaceCache = [];
    private readonly int $skipSamplingAboveY;

    public function __construct(
        int $seed,
        private readonly OverworldBaseDensitySampler $density,
        int $chunkOriginX,
        int $chunkOriginZ,
    ) {
        $factory = XoroshiroPositionalRandomFactory::fromSeed($seed);
        $this->centers = $factory->forString('minecraft:aquifer')->forkPositional();
        $this->barrier = new DoublePerlinNoise($factory->forString('minecraft:aquifer_barrier'), -3, [1.0]);
        $this->floodedness = new DoublePerlinNoise(
            $factory->forString('minecraft:aquifer_fluid_level_floodedness'),
            -7,
            [1.0],
        );
        $this->spread = new DoublePerlinNoise(
            $factory->forString('minecraft:aquifer_fluid_level_spread'),
            -5,
            [1.0],
        );
        $this->lava = new DoublePerlinNoise($factory->forString('minecraft:aquifer_lava'), -1, [1.0]);
        $minGridX = self::floorDiv($chunkOriginX - 5, 16);
        $maxGridX = self::floorDiv($chunkOriginX + 10, 16) + 1;
        $minGridZ = self::floorDiv($chunkOriginZ - 5, 16);
        $maxGridZ = self::floorDiv($chunkOriginZ + 10, 16) + 1;
        $maximumSurface = -64;
        for ($x = $minGridX * 16; $x <= $maxGridX * 16 + 9; $x += 4) {
            for ($z = $minGridZ * 16; $z <= $maxGridZ * 16 + 9; $z += 4) {
                $maximumSurface = max($maximumSurface, $this->preliminarySurface($x, $z));
            }
        }
        $skipGridY = self::floorDiv($maximumSurface + 20, 12) + 1;
        $this->skipSamplingAboveY = $skipGridY * 12 + 10;
    }

    public function materialAt(int $x, int $y, int $z, float $density): OverworldAquiferMaterial
    {
        if ($density > 0.0) {
            return OverworldAquiferMaterial::Stone;
        }
        if ($y < self::GLOBAL_LAVA_LEVEL) {
            return OverworldAquiferMaterial::Lava;
        }
        if ($y > $this->skipSamplingAboveY) {
            return OverworldAquiferMaterial::Air;
        }

        $nearest = $this->nearest($x, $y, $z);
        $first = $this->status($nearest[0]['center']);
        $material = $this->materialAtLevel($first, $y);
        $similarity12 = self::similarity($nearest[0]['distance'], $nearest[1]['distance']);
        if ($similarity12 <= 0.0) {
            return $material;
        }
        if ($material === OverworldAquiferMaterial::Water && $y - 1 < self::GLOBAL_LAVA_LEVEL) {
            return $material;
        }

        $second = $this->status($nearest[1]['center']);
        if ($density + $similarity12 * $this->pressure($x, $y, $z, $first, $second) > 0.0) {
            return OverworldAquiferMaterial::Stone;
        }
        $third = $this->status($nearest[2]['center']);
        $similarity13 = self::similarity($nearest[0]['distance'], $nearest[2]['distance']);
        if ($similarity13 > 0.0
            && $density + $similarity12 * $similarity13 * $this->pressure($x, $y, $z, $first, $third) > 0.0) {
            return OverworldAquiferMaterial::Stone;
        }
        $similarity23 = self::similarity($nearest[1]['distance'], $nearest[2]['distance']);
        if ($similarity23 > 0.0
            && $density + $similarity12 * $similarity23 * $this->pressure($x, $y, $z, $second, $third) > 0.0) {
            return OverworldAquiferMaterial::Stone;
        }

        return $material;
    }

    /** @return list<array{center: array{x: int, y: int, z: int}, distance: int}> */
    public function nearest(int $x, int $y, int $z): array
    {
        $anchorX = self::floorDiv($x - 5, 16);
        $anchorY = self::floorDiv($y + 1, 12);
        $anchorZ = self::floorDiv($z - 5, 16);
        $neighborhoodKey = $anchorX . ':' . $anchorY . ':' . $anchorZ;
        $centers = $this->neighborhoodCache[$neighborhoodKey] ?? null;
        if ($centers === null) {
            $centers = [];
            for ($offsetX = 0; $offsetX <= 1; ++$offsetX) {
                for ($offsetY = -1; $offsetY <= 1; ++$offsetY) {
                    for ($offsetZ = 0; $offsetZ <= 1; ++$offsetZ) {
                        $centers[] = $this->center(
                            $anchorX + $offsetX,
                            $anchorY + $offsetY,
                            $anchorZ + $offsetZ,
                        );
                    }
                }
            }
            $this->neighborhoodCache[$neighborhoodKey] = $centers;
        }
        /** @var list<array{center: array{x: int, y: int, z: int}, distance: int}> $nearest */
        $nearest = [];
        foreach ($centers as $center) {
            $dx = $center['x'] - $x;
            $dy = $center['y'] - $y;
            $dz = $center['z'] - $z;
            $candidate = ['center' => $center, 'distance' => $dx * $dx + $dy * $dy + $dz * $dz];
            $insert = 0;
            while ($insert < count($nearest) && $nearest[$insert]['distance'] <= $candidate['distance']) {
                ++$insert;
            }
            array_splice($nearest, $insert, 0, [$candidate]);
            if (count($nearest) > 4) {
                array_pop($nearest);
            }
        }

        return $nearest;
    }

    /** @param array{x: int, y: int, z: int} $center */
    public function fluidLevel(array $center): int
    {
        return $this->status($center)['level'];
    }

    /** @return array{x: int, y: int, z: int} */
    private function center(int $gridX, int $gridY, int $gridZ): array
    {
        $key = $gridX . ':' . $gridY . ':' . $gridZ;
        if (isset($this->centerCache[$key])) {
            return $this->centerCache[$key];
        }
        $random = $this->centers->forBlockCoordinates($gridX, $gridY, $gridZ);

        return $this->centerCache[$key] = [
            'x' => $gridX * 16 + $random->nextInt(10),
            'y' => $gridY * 12 + $random->nextInt(9),
            'z' => $gridZ * 16 + $random->nextInt(10),
        ];
    }

    /**
     * @param array{x: int, y: int, z: int} $center
     * @return array{level: int, material: OverworldAquiferMaterial}
     */
    private function status(array $center): array
    {
        $key = $center['x'] . ':' . $center['y'] . ':' . $center['z'];
        if (isset($this->statusCache[$key])) {
            return $this->statusCache[$key];
        }
        $lowest = PHP_INT_MAX;
        $surfaceUnderGlobal = false;
        foreach (self::SURFACE_OFFSETS as $index => [$offsetX, $offsetZ]) {
            $surface = $this->preliminarySurface(
                self::floorDiv($center['x'] + $offsetX * 16, 4) * 4,
                self::floorDiv($center['z'] + $offsetZ * 16, 4) * 4,
            );
            $adjusted = $surface + 8;
            $isCenter = $index === 0;
            if ($isCenter && $center['y'] - 12 > $adjusted) {
                return $this->statusCache[$key] = $this->globalStatus($center['y']);
            }
            $topPokesAbove = $center['y'] + 12 > $adjusted;
            if (($topPokesAbove || $isCenter) && $adjusted < self::GLOBAL_WATER_LEVEL) {
                $surfaceUnderGlobal = $surfaceUnderGlobal || $isCenter;
                if ($topPokesAbove) {
                    return $this->statusCache[$key] = ['level' => self::GLOBAL_WATER_LEVEL, 'material' => OverworldAquiferMaterial::Water];
                }
            }
            $lowest = min($lowest, $surface);
        }

        $distanceBelow = $lowest + 8 - $center['y'];
        $factor = $surfaceUnderGlobal ? 1.0 - max(0.0, min(64.0, (float) $distanceBelow)) / 64.0 : 0.0;
        $noise = max(-1.0, min(1.0, $this->floodedness->sample($center['x'], $center['y'] * 0.67, $center['z'])));
        if ($noise > 0.8 - 1.1 * $factor) {
            $level = self::GLOBAL_WATER_LEVEL;
        } elseif ($noise <= 0.4 - 1.2 * $factor) {
            $level = self::DRY_LEVEL;
        } else {
            $cellY = self::floorDiv($center['y'], 40);
            $spread = (int) floor(($this->spread->sample(
                self::floorDiv($center['x'], 16),
                $cellY * (5.0 / 7.0),
                self::floorDiv($center['z'], 16),
            ) * 10.0) / 3.0) * 3;
            $level = min($lowest, $cellY * 40 + 20 + $spread);
        }
        $material = OverworldAquiferMaterial::Water;
        if ($level <= -10 && $level !== self::DRY_LEVEL) {
            $lava = $this->lava->sample(
                self::floorDiv($center['x'], 64),
                self::floorDiv($center['y'], 40),
                self::floorDiv($center['z'], 64),
            );
            if (abs($lava) > 0.3) {
                $material = OverworldAquiferMaterial::Lava;
            }
        }

        return $this->statusCache[$key] = ['level' => $level, 'material' => $material];
    }

    /** @return array{level: int, material: OverworldAquiferMaterial} */
    private function globalStatus(int $y): array
    {
        return $y < self::GLOBAL_LAVA_LEVEL
            ? ['level' => self::GLOBAL_LAVA_LEVEL, 'material' => OverworldAquiferMaterial::Lava]
            : ['level' => self::GLOBAL_WATER_LEVEL, 'material' => OverworldAquiferMaterial::Water];
    }

    /**
     * @param array{level: int, material: OverworldAquiferMaterial} $status
     */
    private function materialAtLevel(array $status, int $y): OverworldAquiferMaterial
    {
        return $y < $status['level'] ? $status['material'] : OverworldAquiferMaterial::Air;
    }

    /**
     * @param array{level: int, material: OverworldAquiferMaterial} $first
     * @param array{level: int, material: OverworldAquiferMaterial} $second
     */
    private function pressure(int $x, int $y, int $z, array $first, array $second): float
    {
        if ($first['material'] !== $second['material']
            && ($first['material'] === OverworldAquiferMaterial::Lava
                || $second['material'] === OverworldAquiferMaterial::Lava)) {
            return 2.0;
        }
        if ($first['level'] === $second['level']) {
            return 0.0;
        }
        $difference = abs($first['level'] - $second['level']);
        $aboveAverage = $y + 0.5 - 0.5 * ($first['level'] + $second['level']);
        $edgeDistance = $difference / 2.0 - abs($aboveAverage);
        if ($aboveAverage > 0.0) {
            $gradient = $edgeDistance > 0.0 ? $edgeDistance / 1.5 : $edgeDistance / 2.5;
        } else {
            $center = 3.0 + $edgeDistance;
            $gradient = $center > 0.0 ? $center / 3.0 : $center / 10.0;
        }
        $noise = $gradient < -2.0 || $gradient > 2.0 ? 0.0 : $this->barrier->sample($x, $y * 0.5, $z);

        return 2.0 * ($noise + $gradient);
    }

    private static function similarity(int $firstDistance, int $secondDistance): float
    {
        return 1.0 - ($secondDistance - $firstDistance) / 25.0;
    }

    private function preliminarySurface(int $x, int $z): int
    {
        $key = $x . ':' . $z;

        return $this->surfaceCache[$key] ??= $this->density->preliminarySurfaceLevel($x, $z, -64, 320);
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
