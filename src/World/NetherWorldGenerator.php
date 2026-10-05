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

namespace Bedriox\Server\World;

use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\Generation\MutableChunkBuilder;
use Bedriox\Server\World\Generation\SeededNoise;

/** Deterministic bounded cavern terrain and structures for the built-in Nether dimension. */
final readonly class NetherWorldGenerator implements VersionedWorldGenerator
{
    public const int VERSION = 3;
    private const int MAXIMUM_Y = 127;
    private const int LAVA_LEVEL = 31;
    private const int SPAWN_SEARCH_CHUNK_RADIUS = 4;
    private const int SPAWN_MINIMUM_FLOOR_Y = self::LAVA_LEVEL + 2;
    private const int SPAWN_MAXIMUM_FLOOR_Y = 120;
    private const int STRUCTURE_REGION = 384;
    private const int STRUCTURE_RADIUS = 42;

    private GenerationBlockPalette $blocks;
    private SeededNoise $noise;

    public function __construct(int $seed, BlockStateRegistry $states)
    {
        $this->blocks = GenerationBlockPalette::fromRegistry($states);
        $this->noise = new SeededNoise($seed ^ 0x4e455448);
    }

    public function name(): string
    {
        return 'default';
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function generate(ChunkPosition $position): Chunk
    {
        $builder = new MutableChunkBuilder($position, $this->blocks);
        $originX = $position->x * 16;
        $originZ = $position->z * 16;
        $biome = $this->biomeAt($originX + 8, $originZ + 8);
        $air = $this->blocks->state('minecraft:air');
        $netherrack = $this->blocks->state('minecraft:netherrack');
        $biomeColumns = [];

        for ($localX = 0; $localX < 16; ++$localX) {
            $worldX = $originX + $localX;
            for ($localZ = 0; $localZ < 16; ++$localZ) {
                $worldZ = $originZ + $localZ;
                $columnBiome = $this->biomeAt($worldX, $worldZ);
                $shelfCenter = 61 + intdiv($this->noise->fractal2d($worldX, $worldZ, 144, 3, 55, 787), 4_096);
                $biomeColumns[$localX + $localZ * 16] = new Biome($columnBiome);
                for ($y = 0; $y <= self::MAXIMUM_Y; ++$y) {
                    if ($this->isBedrock($worldX, $y, $worldZ)) {
                        $builder->set($localX, $y, $localZ, $this->blocks->state('minecraft:bedrock'));
                    } elseif ($this->isNaturalSolid($worldX, $y, $worldZ, $columnBiome, $shelfCenter)) {
                        $builder->set($localX, $y, $localZ, $this->naturalSolidState($worldX, $y, $worldZ));
                    } elseif ($y <= self::LAVA_LEVEL) {
                        $builder->set($localX, $y, $localZ, $this->blocks->state('minecraft:lava'));
                    }
                }

                for ($y = 2; $y < self::MAXIMUM_Y - 1; ++$y) {
                    if ($builder->state($localX, $y, $localZ)->value !== $netherrack->value
                        || $builder->state($localX, $y + 1, $localZ)->value !== $air->value) {
                        continue;
                    }
                    $builder->set($localX, $y, $localZ, $this->floorState($columnBiome, $worldX, $y, $worldZ));
                }
            }
        }

        $this->decorateVegetation($builder, $position);
        $this->decorateGroundCover($builder, $position);
        $this->decorateBasaltColumns($builder, $position);
        $this->decorateLavaFalls($builder, $position);
        $this->decorateGlowstone($builder, $position);
        $this->decorateStructures($builder, $position);

        ksort($biomeColumns, SORT_NUMERIC);
        $biomeStorage = BiomeStorage::fromColumns(array_values($biomeColumns));

        return new Chunk(
            $position,
            $air,
            $builder->sections(),
            new Biome($biome),
            finalizationState: ChunkFinalizationState::Done,
            biomeStorages: array_fill(0, 8, $biomeStorage),
        );
    }

    public function defaultSpawn(): SpawnPosition
    {
        $air = $this->blocks->state('minecraft:air')->value;
        $unsafeFooting = array_fill_keys([
            $air,
            $this->blocks->state('minecraft:lava')->value,
            $this->blocks->state('minecraft:magma')->value,
            $this->blocks->state('minecraft:crimson_roots')->value,
            $this->blocks->state('minecraft:warped_roots')->value,
            $this->blocks->state('minecraft:nether_sprouts')->value,
        ], true);
        for ($radius = 0; $radius <= self::SPAWN_SEARCH_CHUNK_RADIUS; ++$radius) {
            for ($chunkZ = -$radius; $chunkZ <= $radius; ++$chunkZ) {
                for ($chunkX = -$radius; $chunkX <= $radius; ++$chunkX) {
                    if ($radius !== 0 && abs($chunkX) !== $radius && abs($chunkZ) !== $radius) {
                        continue;
                    }
                    $chunk = $this->generate(new ChunkPosition($chunkX, $chunkZ));
                    for ($localZ = 0; $localZ < 16; ++$localZ) {
                        for ($localX = 0; $localX < 16; ++$localX) {
                            for ($floorY = self::SPAWN_MAXIMUM_FLOOR_Y; $floorY >= self::SPAWN_MINIMUM_FLOOR_Y; --$floorY) {
                                $floor = $chunk->blockStateAt($localX, $floorY, $localZ)->value;
                                if (isset($unsafeFooting[$floor])
                                    || $chunk->blockStateAt($localX, $floorY + 1, $localZ)->value !== $air
                                    || $chunk->blockStateAt($localX, $floorY + 2, $localZ)->value !== $air) {
                                    continue;
                                }

                                return new SpawnPosition(
                                    $chunkX * 16 + $localX,
                                    $floorY + 1,
                                    $chunkZ * 16 + $localZ,
                                );
                            }
                        }
                    }
                }
            }
        }

        throw new \RuntimeException('Nether generation did not produce a safe default spawn within the bounded search area.');
    }

    private function biomeAt(int $x, int $z): string
    {
        $climate = $this->noise->fractal2d($x, $z, 224, 3, 55, 1_109);
        $humidity = $this->noise->fractal2d($x, $z, 192, 3, 58, 1_213);
        if ($climate < -14_000) {
            return 'minecraft:soulsand_valley';
        }
        if ($climate > 15_000) {
            return 'minecraft:basalt_deltas';
        }
        if ($humidity > 8_000) {
            return 'minecraft:warped_forest';
        }
        if ($humidity < -8_000) {
            return 'minecraft:crimson_forest';
        }

        return 'minecraft:hell';
    }

    private function isBedrock(int $x, int $y, int $z): bool
    {
        return $y === 0 || $y === self::MAXIMUM_Y
            || ($y < 5 && $this->noise->chance($x, $y, $z, 701, 5) > $y)
            || ($y > 122 && $this->noise->chance($x, $y, $z, 709, 5) >= self::MAXIMUM_Y - $y);
    }

    private function isNaturalSolid(
        int $x,
        int $y,
        int $z,
        ?string $biome = null,
        ?int $shelfCenter = null,
    ): bool {
        if ($y <= 0 || $y >= self::MAXIMUM_Y) {
            return true;
        }
        $biome ??= $this->biomeAt($x, $z);
        $shelfCenter ??= 61 + intdiv($this->noise->fractal2d($x, $z, 144, 3, 55, 787), 4_096);
        $edgePressure = max(0, 27 - min($y, self::MAXIMUM_Y - $y)) * 1_100;
        $largeCaves = $this->noise->fractal3d($x, $y, $z, 92, 4, 55, 811);
        $detail = $this->noise->fractal3d($x, $y, $z, 29, 2, 50, 977);
        $shelfPressure = max(0, 8 - abs($y - $shelfCenter)) * 620;
        $biomePressure = match ($biome) {
            'minecraft:basalt_deltas' => 1_350,
            'minecraft:crimson_forest' => 500,
            'minecraft:warped_forest' => 150,
            'minecraft:soulsand_valley' => -1_350,
            default => 0,
        };
        if ($y > 12 && $y < 116 && abs($detail) < 1_150 && $largeCaves < 7_000) {
            return false;
        }

        return $largeCaves + intdiv($detail, 3) + $edgePressure + $shelfPressure + $biomePressure > 3_100;
    }

    private function naturalSolidState(int $x, int $y, int $z): InternalBlockStateId
    {
        $ore = $this->noise->chance($x, $y, $z, 1_333, 1_024);
        if ($ore < 6) {
            return $this->blocks->state('minecraft:quartz_ore');
        }
        if ($ore === 6) {
            return $this->blocks->state('minecraft:nether_gold_ore');
        }

        return $this->blocks->state('minecraft:netherrack');
    }

    private function floorState(string $biome, int $x, int $y, int $z): InternalBlockStateId
    {
        return match ($biome) {
            'minecraft:basalt_deltas' => $this->blocks->state(
                $this->noise->chance($x, $y, $z, 1_301, 4) === 0 ? 'minecraft:blackstone' : 'minecraft:basalt',
            ),
            'minecraft:soulsand_valley' => $this->blocks->state(
                $this->noise->chance($x, $y, $z, 1_319, 3) === 0 ? 'minecraft:soul_soil' : 'minecraft:soul_sand',
            ),
            'minecraft:crimson_forest' => $this->blocks->state('minecraft:crimson_nylium'),
            'minecraft:warped_forest' => $this->blocks->state('minecraft:warped_nylium'),
            default => $this->blocks->state(match ($this->noise->chance($x, $y, $z, 1_327, 37)) {
                0 => 'minecraft:magma',
                1, 2, 3 => 'minecraft:gravel',
                default => 'minecraft:netherrack',
            }),
        };
    }

    private function decorateGroundCover(MutableChunkBuilder $builder, ChunkPosition $position): void
    {
        $minimumX = $position->x * 16 - 2;
        $minimumZ = $position->z * 16 - 2;
        for ($cellX = self::floorDiv($minimumX, 5); $cellX <= self::floorDiv($minimumX + 19, 5); ++$cellX) {
            for ($cellZ = self::floorDiv($minimumZ, 5); $cellZ <= self::floorDiv($minimumZ + 19, 5); ++$cellZ) {
                $x = $cellX * 5 + $this->noise->chance($cellX, 0, $cellZ, 1_371, 5);
                $z = $cellZ * 5 + $this->noise->chance($cellX, 1, $cellZ, 1_373, 5);
                $floor = $this->openFloorAt($x, $z);
                if ($floor === null || $this->noise->chance($x, $floor, $z, 1_379, 3) !== 0) {
                    continue;
                }
                $identifier = match ($this->biomeAt($x, $z)) {
                    'minecraft:soulsand_valley' => 'minecraft:soul_fire',
                    'minecraft:crimson_forest' => $this->noise->chance($x, $floor, $z, 1_383, 4) === 0
                        ? 'minecraft:red_mushroom'
                        : 'minecraft:crimson_roots',
                    'minecraft:warped_forest' => $this->noise->chance($x, $floor, $z, 1_389, 4) === 0
                        ? 'minecraft:brown_mushroom'
                        : 'minecraft:nether_sprouts',
                    'minecraft:hell' => 'minecraft:fire',
                    default => null,
                };
                if ($identifier !== null) {
                    $builder->setWorld($x, $floor + 1, $z, $this->blocks->state($identifier), true);
                }
            }
        }
    }

    private function decorateBasaltColumns(MutableChunkBuilder $builder, ChunkPosition $position): void
    {
        $minimumX = $position->x * 16 - 4;
        $minimumZ = $position->z * 16 - 4;
        for ($cellX = self::floorDiv($minimumX, 11); $cellX <= self::floorDiv($minimumX + 23, 11); ++$cellX) {
            for ($cellZ = self::floorDiv($minimumZ, 11); $cellZ <= self::floorDiv($minimumZ + 23, 11); ++$cellZ) {
                $x = $cellX * 11 + 2 + $this->noise->chance($cellX, 0, $cellZ, 1_411, 7);
                $z = $cellZ * 11 + 2 + $this->noise->chance($cellX, 1, $cellZ, 1_419, 7);
                if ($this->biomeAt($x, $z) !== 'minecraft:basalt_deltas'
                    || $this->noise->chance($x, 0, $z, 1_421, 3) !== 0) {
                    continue;
                }
                $floor = $this->openFloorAt($x, $z);
                if ($floor === null) {
                    continue;
                }
                $height = 2 + $this->noise->chance($x, $floor, $z, 1_423, 8);
                for ($vertical = 1; $vertical <= $height; ++$vertical) {
                    $builder->setWorld($x, $floor + $vertical, $z, $this->blocks->state('minecraft:basalt'), true);
                }
            }
        }
    }

    private function decorateLavaFalls(MutableChunkBuilder $builder, ChunkPosition $position): void
    {
        if ($this->noise->chance($position->x, 0, $position->z, 1_451, 6) !== 0) {
            return;
        }
        $x = $position->x * 16 + 2 + $this->noise->chance($position->x, 1, $position->z, 1_457, 12);
        $z = $position->z * 16 + 2 + $this->noise->chance($position->x, 2, $position->z, 1_459, 12);
        $biome = $this->biomeAt($x, $z);
        $shelfCenter = 61 + intdiv($this->noise->fractal2d($x, $z, 144, 3, 55, 787), 4_096);
        for ($y = 116; $y >= 38; --$y) {
            if ($this->isNaturalSolid($x, $y + 1, $z, $biome, $shelfCenter)
                && !$this->isNaturalSolid($x, $y, $z, $biome, $shelfCenter)) {
                $length = 4 + $this->noise->chance($x, $y, $z, 1_463, 10);
                for ($vertical = 0; $vertical < $length; ++$vertical) {
                    if ($this->isNaturalSolid($x, $y - $vertical, $z, $biome, $shelfCenter)) {
                        break;
                    }
                    $builder->setWorld($x, $y - $vertical, $z, $this->blocks->state('minecraft:lava'), true);
                }

                return;
            }
        }
    }

    private function decorateVegetation(MutableChunkBuilder $builder, ChunkPosition $position): void
    {
        $minimumX = $position->x * 16 - 5;
        $maximumX = $minimumX + 25;
        $minimumZ = $position->z * 16 - 5;
        $maximumZ = $minimumZ + 25;
        for ($cellX = self::floorDiv($minimumX, 12); $cellX <= self::floorDiv($maximumX, 12); ++$cellX) {
            for ($cellZ = self::floorDiv($minimumZ, 12); $cellZ <= self::floorDiv($maximumZ, 12); ++$cellZ) {
                $x = $cellX * 12 + 2 + $this->noise->chance($cellX, 0, $cellZ, 1_501, 8);
                $z = $cellZ * 12 + 2 + $this->noise->chance($cellX, 1, $cellZ, 1_503, 8);
                $biome = $this->biomeAt($x, $z);
                if (($biome !== 'minecraft:crimson_forest' && $biome !== 'minecraft:warped_forest')
                    || $this->noise->chance($cellX, 2, $cellZ, 1_509, 4) !== 0) {
                    continue;
                }
                $floor = $this->openFloorAt($x, $z);
                if ($floor === null) {
                    continue;
                }
                $stem = $biome === 'minecraft:crimson_forest' ? 'minecraft:crimson_stem' : 'minecraft:warped_stem';
                $cap = $biome === 'minecraft:crimson_forest' ? 'minecraft:nether_wart_block' : 'minecraft:warped_wart_block';
                $roots = $biome === 'minecraft:crimson_forest' ? 'minecraft:crimson_roots' : 'minecraft:warped_roots';
                $height = 4 + $this->noise->chance($x, $floor, $z, 1_521, 5);
                for ($offset = 1; $offset <= $height; ++$offset) {
                    $builder->setWorld($x, $floor + $offset, $z, $this->blocks->state($stem));
                }
                for ($dx = -2; $dx <= 2; ++$dx) {
                    for ($dz = -2; $dz <= 2; ++$dz) {
                        if (abs($dx) + abs($dz) > 3) {
                            continue;
                        }
                        $builder->setWorld($x + $dx, $floor + $height, $z + $dz, $this->blocks->state($cap), true);
                        if (abs($dx) <= 1 && abs($dz) <= 1) {
                            $builder->setWorld($x + $dx, $floor + $height + 1, $z + $dz, $this->blocks->state($cap), true);
                        }
                    }
                }
                if ($this->noise->chance($x, $floor, $z, 1_523, 3) === 0) {
                    $builder->setWorld($x + 2, $floor + 1, $z, $this->blocks->state('minecraft:shroomlight'), true);
                }
                $builder->setWorld($x + 1, $floor + 1, $z + 1, $this->blocks->state($roots), true);
            }
        }
    }

    private function decorateGlowstone(MutableChunkBuilder $builder, ChunkPosition $position): void
    {
        $originX = $position->x * 16;
        $originZ = $position->z * 16;
        for ($cellX = 0; $cellX < 2; ++$cellX) {
            for ($cellZ = 0; $cellZ < 2; ++$cellZ) {
                $x = $originX + $cellX * 8 + $this->noise->chance($position->x, $cellX, $position->z, 1_601 + $cellZ, 8);
                $z = $originZ + $cellZ * 8 + $this->noise->chance($position->x, $cellZ, $position->z, 1_607 + $cellX, 8);
                if ($this->noise->chance($x, 0, $z, 1_613, 5) !== 0) {
                    continue;
                }
                for ($y = 119; $y >= 35; --$y) {
                    if (!$this->isNaturalSolid($x, $y, $z) && $this->isNaturalSolid($x, $y + 1, $z)) {
                        $length = 1 + $this->noise->chance($x, $y, $z, 1_619, 4);
                        for ($offset = 0; $offset < $length; ++$offset) {
                            $builder->setWorld($x, $y - $offset, $z, $this->blocks->state('minecraft:glowstone'), true);
                        }
                        break;
                    }
                }
            }
        }
    }

    private function decorateStructures(MutableChunkBuilder $builder, ChunkPosition $position): void
    {
        $chunkMinimumX = $position->x * 16;
        $chunkMaximumX = $chunkMinimumX + 15;
        $chunkMinimumZ = $position->z * 16;
        $chunkMaximumZ = $chunkMinimumZ + 15;
        $minimumX = $chunkMinimumX - self::STRUCTURE_RADIUS;
        $maximumX = $chunkMaximumX + self::STRUCTURE_RADIUS;
        $minimumZ = $chunkMinimumZ - self::STRUCTURE_RADIUS;
        $maximumZ = $chunkMaximumZ + self::STRUCTURE_RADIUS;
        for ($regionX = self::floorDiv($minimumX, self::STRUCTURE_REGION); $regionX <= self::floorDiv($maximumX, self::STRUCTURE_REGION); ++$regionX) {
            for ($regionZ = self::floorDiv($minimumZ, self::STRUCTURE_REGION); $regionZ <= self::floorDiv($maximumZ, self::STRUCTURE_REGION); ++$regionZ) {
                if ($this->noise->chance($regionX, 0, $regionZ, 1_701, 3) !== 0) {
                    continue;
                }
                $centerX = $regionX * self::STRUCTURE_REGION + 96 + $this->noise->chance($regionX, 1, $regionZ, 1_709, 192);
                $centerZ = $regionZ * self::STRUCTURE_REGION + 96 + $this->noise->chance($regionX, 2, $regionZ, 1_717, 192);
                if ($centerX + 34 < $chunkMinimumX || $centerX - 34 > $chunkMaximumX
                    || $centerZ + 34 < $chunkMinimumZ || $centerZ - 34 > $chunkMaximumZ) {
                    continue;
                }
                $baseY = 48 + $this->noise->chance($regionX, 3, $regionZ, 1_723, 23);
                $this->renderNetherStructure(
                    $builder,
                    $centerX,
                    $baseY,
                    $centerZ,
                    $this->noise->chance($regionX, 4, $regionZ, 1_727, 4) === 0,
                );
            }
        }
    }

    private function renderNetherStructure(MutableChunkBuilder $builder, int $centerX, int $baseY, int $centerZ, bool $bastion): void
    {
        $material = $this->blocks->state($bastion ? 'minecraft:polished_blackstone_bricks' : 'minecraft:nether_brick');
        $air = $this->blocks->state('minecraft:air');
        for ($x = $centerX - 34; $x <= $centerX + 34; ++$x) {
            for ($z = $centerZ - 34; $z <= $centerZ + 34; ++$z) {
                $dx = abs($x - $centerX);
                $dz = abs($z - $centerZ);
                $corridor = ($dx <= 3 && $dz <= 34) || ($dz <= 3 && $dx <= 34);
                $room = ($dx >= 25 && $dz <= 8) || ($dz >= 25 && $dx <= 8) || ($dx <= 8 && $dz <= 8);
                if (!$corridor && !$room) {
                    continue;
                }
                $edge = ($dx === 3 && $dz <= 24) || ($dz === 3 && $dx <= 24)
                    || ($room && ($dx === 8 || $dz === 8 || $dx === 25 || $dz === 25 || $dx === 34 || $dz === 34));
                $builder->setWorld($x, $baseY, $z, $material);
                for ($y = $baseY + 1; $y <= $baseY + 5; ++$y) {
                    $builder->setWorld($x, $y, $z, $edge ? $material : $air);
                }
                if ($room || ($x - $centerX) % 7 === 0 || ($z - $centerZ) % 7 === 0) {
                    $builder->setWorld($x, $baseY + 6, $z, $material);
                }
            }
        }
        if ($bastion) {
            $builder->setWorld($centerX, $baseY + 1, $centerZ, $this->blocks->state('minecraft:gold_block'));
        }
    }

    private function openFloorAt(int $x, int $z): ?int
    {
        $biome = $this->biomeAt($x, $z);
        $shelfCenter = 61 + intdiv($this->noise->fractal2d($x, $z, 144, 3, 55, 787), 4_096);
        for ($y = 112; $y >= 34; --$y) {
            if ($this->isNaturalSolid($x, $y, $z, $biome, $shelfCenter)
                && !$this->isNaturalSolid($x, $y + 1, $z, $biome, $shelfCenter)) {
                return $y;
            }
        }

        return null;
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);
        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
