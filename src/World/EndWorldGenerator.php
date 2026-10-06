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

use Bedriox\Server\World\Block\BlockAxis;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\Generation\MutableChunkBuilder;
use Bedriox\Server\World\Generation\SeededNoise;

/** Deterministic bounded island, landmark, and structure terrain for the built-in End dimension. */
final readonly class EndWorldGenerator implements VersionedWorldGenerator
{
    public const int VERSION = 4;
    private const int CITY_REGION = 512;
    private const int CITY_RADIUS = 104;

    /** @var list<array{int, int, int, int}> x, z, radius, height */
    private const array PILLARS = [
        [42, 0, 3, 76], [34, 25, 3, 79], [13, 40, 4, 82], [-13, 40, 4, 85], [-34, 25, 4, 88],
        [-42, 0, 5, 91], [-34, -25, 5, 94], [-13, -40, 5, 97], [13, -40, 6, 100], [34, -25, 6, 103],
    ];

    private GenerationBlockPalette $blocks;
    private SeededNoise $noise;

    public function __construct(int $seed, BlockStateRegistry $states)
    {
        $this->blocks = GenerationBlockPalette::fromRegistry($states);
        $this->noise = new SeededNoise($seed ^ 0x454e4421);
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
        for ($localX = 0; $localX < 16; ++$localX) {
            $worldX = $originX + $localX;
            for ($localZ = 0; $localZ < 16; ++$localZ) {
                $worldZ = $originZ + $localZ;
                $surface = $this->surfaceAt($worldX, $worldZ);
                if ($surface === null) {
                    continue;
                }
                $thickness = $this->thicknessAt($worldX, $worldZ);
                for ($y = max(0, $surface - $thickness); $y <= $surface; ++$y) {
                    $edgeNoise = $this->noise->fractal3d($worldX, $y, $worldZ, 30, 2, 55, 1_759);
                    $depth = $surface - $y;
                    if ($depth > 2 || $edgeNoise + $depth * 4_000 > -7_000) {
                        $builder->set($localX, $y, $localZ, $this->blocks->state('minecraft:end_stone'));
                    }
                }
            }
        }

        $this->decorateChorus($builder, $position);
        $this->renderCentralLandmarks($builder);
        $this->decorateCities($builder, $position);

        return new Chunk(
            $position,
            $this->blocks->state('minecraft:air'),
            $builder->sections(),
            new Biome('minecraft:the_end'),
            finalizationState: ChunkFinalizationState::Done,
        );
    }

    public function defaultSpawn(): SpawnPosition
    {
        return new SpawnPosition(100, 49, 0);
    }

    private function surfaceAt(int $x, int $z): ?int
    {
        $radius = hypot((float) $x, (float) $z);
        $mainIsland = max(0.0, 1.0 - $radius / 520.0);
        $outerBand = $radius > 980.0
            ? max(0.0, ($this->noise->fractal2d($x, $z, 176, 4, 55, 1_701) + 11_000) / 43_000.0)
            : 0.0;
        $strength = max($mainIsland, $outerBand);
        if ($strength <= 0.09) {
            return null;
        }

        return 57 + (int) round($strength * 12.0)
            + intdiv($this->noise->fractal2d($x, $z, 56, 3, 52, 1_733), 5_200);
    }

    private function thicknessAt(int $x, int $z): int
    {
        $radius = hypot((float) $x, (float) $z);
        $mainStrength = max(0.0, 1.0 - $radius / 520.0);

        return 4 + (int) round($mainStrength * 27.0)
            + abs(intdiv($this->noise->fractal2d($x, $z, 80, 2, 50, 1_747), 4_500));
    }

    private function decorateChorus(MutableChunkBuilder $builder, ChunkPosition $position): void
    {
        $minimumX = $position->x * 16 - 4;
        $maximumX = $minimumX + 23;
        $minimumZ = $position->z * 16 - 4;
        $maximumZ = $minimumZ + 23;
        for ($cellX = self::floorDiv($minimumX, 11); $cellX <= self::floorDiv($maximumX, 11); ++$cellX) {
            for ($cellZ = self::floorDiv($minimumZ, 11); $cellZ <= self::floorDiv($maximumZ, 11); ++$cellZ) {
                $x = $cellX * 11 + 2 + $this->noise->chance($cellX, 0, $cellZ, 1_801, 7);
                $z = $cellZ * 11 + 2 + $this->noise->chance($cellX, 1, $cellZ, 1_803, 7);
                if (hypot((float) $x, (float) $z) < 980.0
                    || $this->noise->chance($cellX, 2, $cellZ, 1_807, 5) !== 0) {
                    continue;
                }
                $surface = $this->surfaceAt($x, $z);
                if ($surface === null) {
                    continue;
                }
                $height = 3 + $this->noise->chance($x, $surface, $z, 1_811, 5);
                for ($offset = 1; $offset <= $height; ++$offset) {
                    $builder->setWorld($x, $surface + $offset, $z, $this->blocks->state(
                        $offset === $height ? 'minecraft:chorus_flower' : 'minecraft:chorus_plant',
                    ), true);
                }
                if ($height >= 5) {
                    $branchY = $surface + $height - 2;
                    $builder->setWorld($x + 1, $branchY, $z, $this->blocks->state('minecraft:chorus_plant'), true);
                    $builder->setWorld($x + 2, $branchY, $z, $this->blocks->state('minecraft:chorus_flower'), true);
                }
            }
        }
    }

    private function renderCentralLandmarks(MutableChunkBuilder $builder): void
    {
        $chunkMinimumX = $builder->position->x * 16;
        $chunkMaximumX = $chunkMinimumX + 15;
        $chunkMinimumZ = $builder->position->z * 16;
        $chunkMaximumZ = $chunkMinimumZ + 15;
        foreach (self::PILLARS as [$centerX, $centerZ, $radius, $height]) {
            if ($centerX + $radius < $chunkMinimumX || $centerX - $radius > $chunkMaximumX
                || $centerZ + $radius < $chunkMinimumZ || $centerZ - $radius > $chunkMaximumZ) {
                continue;
            }
            for ($dx = -6; $dx <= 6; ++$dx) {
                for ($dz = -6; $dz <= 6; ++$dz) {
                    if (hypot((float) $dx, (float) $dz) > $radius) {
                        continue;
                    }
                    for ($y = 45; $y <= $height; ++$y) {
                        $builder->setWorld($centerX + $dx, $y, $centerZ + $dz, $this->blocks->state('minecraft:obsidian'));
                    }
                }
            }
            $builder->setWorld($centerX, $height + 1, $centerZ, $this->blocks->state('minecraft:bedrock'));
            if ($radius >= 5) {
                for ($offset = -2; $offset <= 2; ++$offset) {
                    for ($y = $height + 1; $y <= $height + 3; ++$y) {
                        $builder->setWorld($centerX - 2, $y, $centerZ + $offset, $this->blocks->state('minecraft:iron_bars'));
                        $builder->setWorld($centerX + 2, $y, $centerZ + $offset, $this->blocks->state('minecraft:iron_bars'));
                        $builder->setWorld($centerX + $offset, $y, $centerZ - 2, $this->blocks->state('minecraft:iron_bars'));
                        $builder->setWorld($centerX + $offset, $y, $centerZ + 2, $this->blocks->state('minecraft:iron_bars'));
                    }
                }
            }
        }

        for ($x = -3; $x <= 3; ++$x) {
            for ($z = -3; $z <= 3; ++$z) {
                $distance = max(abs($x), abs($z));
                if ($distance === 3) {
                    $builder->setWorld($x, 69, $z, $this->blocks->state('minecraft:bedrock'));
                } elseif ($distance <= 1) {
                    // The exit remains closed until the authoritative first dragon victory.
                    $builder->setWorld($x, 69, $z, $this->blocks->state('minecraft:air'));
                }
            }
        }
        for ($y = 70; $y <= 73; ++$y) {
            $builder->setWorld(0, $y, 0, $this->blocks->state('minecraft:bedrock'));
        }

        $air = $this->blocks->state('minecraft:air');
        for ($x = 98; $x <= 102; ++$x) {
            for ($z = -2; $z <= 2; ++$z) {
                $builder->setWorld($x, 48, $z, $this->blocks->state('minecraft:obsidian'));
                for ($y = 49; $y <= 52; ++$y) {
                    $builder->setWorld($x, $y, $z, $air);
                }
            }
        }
    }

    private function decorateCities(MutableChunkBuilder $builder, ChunkPosition $position): void
    {
        $chunkMinimumX = $position->x * 16;
        $chunkMaximumX = $chunkMinimumX + 15;
        $chunkMinimumZ = $position->z * 16;
        $chunkMaximumZ = $chunkMinimumZ + 15;
        $minimumX = $chunkMinimumX - self::CITY_RADIUS;
        $maximumX = $chunkMaximumX + self::CITY_RADIUS;
        $minimumZ = $chunkMinimumZ - self::CITY_RADIUS;
        $maximumZ = $chunkMaximumZ + self::CITY_RADIUS;
        for ($regionX = self::floorDiv($minimumX, self::CITY_REGION); $regionX <= self::floorDiv($maximumX, self::CITY_REGION); ++$regionX) {
            for ($regionZ = self::floorDiv($minimumZ, self::CITY_REGION); $regionZ <= self::floorDiv($maximumZ, self::CITY_REGION); ++$regionZ) {
                if ($this->noise->chance($regionX, 0, $regionZ, 1_901, 4) !== 0) {
                    continue;
                }
                $centerX = $regionX * self::CITY_REGION + 128 + $this->noise->chance($regionX, 1, $regionZ, 1_907, 256);
                $centerZ = $regionZ * self::CITY_REGION + 128 + $this->noise->chance($regionX, 2, $regionZ, 1_913, 256);
                if ($centerX + self::CITY_RADIUS < $chunkMinimumX || $centerX - self::CITY_RADIUS > $chunkMaximumX
                    || $centerZ + self::CITY_RADIUS < $chunkMinimumZ || $centerZ - self::CITY_RADIUS > $chunkMaximumZ) {
                    continue;
                }
                if (hypot((float) $centerX, (float) $centerZ) < 1_150.0) {
                    continue;
                }
                $surface = $this->surfaceAt($centerX, $centerZ);
                if ($surface === null) {
                    continue;
                }
                $this->renderEndCity(
                    $builder,
                    $centerX,
                    $surface + 1,
                    $centerZ,
                    $this->noise->chance($regionX, 3, $regionZ, 1_919, 2) === 0,
                );
                if ($this->noise->chance($regionX, 4, $regionZ, 1_921, 2) === 0) {
                    $alongX = $this->noise->chance($regionX, 3, $regionZ, 1_919, 2) === 0;
                    $this->renderEndShip(
                        $builder,
                        $centerX + ($alongX ? 34 : 62),
                        $surface + 20,
                        $centerZ + ($alongX ? 62 : 34),
                        $alongX,
                    );
                }
            }
        }
    }

    private function renderEndCity(MutableChunkBuilder $builder, int $centerX, int $baseY, int $centerZ, bool $alongX): void
    {
        $purpur = $this->blocks->state('minecraft:purpur_block');
        $pillar = $this->blocks->state('minecraft:purpur_pillar', BlockAxis::Y);
        $air = $this->blocks->state('minecraft:air');
        for ($level = 0; $level < 3; ++$level) {
            $floorY = $baseY + $level * 7;
            $radius = $level === 2 ? 5 : 4;
            for ($dx = -$radius; $dx <= $radius; ++$dx) {
                for ($dz = -$radius; $dz <= $radius; ++$dz) {
                    $edge = abs($dx) === $radius || abs($dz) === $radius;
                    $builder->setWorld($centerX + $dx, $floorY, $centerZ + $dz, $purpur);
                    for ($dy = 1; $dy <= 5; ++$dy) {
                        $builder->setWorld($centerX + $dx, $floorY + $dy, $centerZ + $dz, $edge ? $pillar : $air);
                    }
                    $builder->setWorld($centerX + $dx, $floorY + 6, $centerZ + $dz, $purpur);
                }
            }
        }

        $bridgeY = $baseY + 13;
        for ($offset = 6; $offset <= 30; ++$offset) {
            $x = $centerX + ($alongX ? $offset : 0);
            $z = $centerZ + ($alongX ? 0 : $offset);
            for ($side = -1; $side <= 1; ++$side) {
                $builder->setWorld($x + ($alongX ? 0 : $side), $bridgeY, $z + ($alongX ? $side : 0), $purpur);
            }
        }
        $roomX = $centerX + ($alongX ? 34 : 0);
        $roomZ = $centerZ + ($alongX ? 0 : 34);
        for ($dx = -5; $dx <= 5; ++$dx) {
            for ($dz = -5; $dz <= 5; ++$dz) {
                $edge = abs($dx) === 5 || abs($dz) === 5;
                $builder->setWorld($roomX + $dx, $bridgeY, $roomZ + $dz, $purpur);
                for ($dy = 1; $dy <= 6; ++$dy) {
                    $builder->setWorld($roomX + $dx, $bridgeY + $dy, $roomZ + $dz, $edge ? $pillar : $air);
                }
                $builder->setWorld($roomX + $dx, $bridgeY + 7, $roomZ + $dz, $purpur);
            }
        }
    }

    private function renderEndShip(MutableChunkBuilder $builder, int $centerX, int $baseY, int $centerZ, bool $alongX): void
    {
        $purpur = $this->blocks->state('minecraft:purpur_block');
        $pillar = $this->blocks->state('minecraft:purpur_pillar', BlockAxis::Y);
        $air = $this->blocks->state('minecraft:air');
        $length = 22;
        for ($forward = -$length; $forward <= $length; ++$forward) {
            $halfWidth = max(1, min(5, intdiv($length - abs($forward), 3) + 1));
            for ($side = -$halfWidth; $side <= $halfWidth; ++$side) {
                $x = $centerX + ($alongX ? $forward : $side);
                $z = $centerZ + ($alongX ? $side : $forward);
                $hullDepth = max(1, $halfWidth - abs($side));
                $builder->setWorld($x, $baseY, $z, $purpur, true);
                for ($depth = 1; $depth <= $hullDepth; ++$depth) {
                    $builder->setWorld($x, $baseY - $depth, $z, $pillar, true);
                }
                for ($height = 1; $height <= 5; ++$height) {
                    $builder->setWorld($x, $baseY + $height, $z, $air, true);
                }
            }
        }

        for ($forward = -5; $forward <= 5; ++$forward) {
            for ($side = -4; $side <= 4; ++$side) {
                $x = $centerX + ($alongX ? $forward : $side);
                $z = $centerZ + ($alongX ? $side : $forward);
                $edge = abs($forward) === 5 || abs($side) === 4;
                for ($height = 1; $height <= 4; ++$height) {
                    $builder->setWorld($x, $baseY + $height, $z, $edge ? $pillar : $air, true);
                }
                $builder->setWorld($x, $baseY + 5, $z, $purpur, true);
            }
        }

        $mastX = $centerX + ($alongX ? -7 : 0);
        $mastZ = $centerZ + ($alongX ? 0 : -7);
        for ($height = 1; $height <= 13; ++$height) {
            $builder->setWorld($mastX, $baseY + $height, $mastZ, $pillar, true);
        }
        $bowX = $centerX + ($alongX ? $length + 1 : 0);
        $bowZ = $centerZ + ($alongX ? 0 : $length + 1);
        $builder->setWorld($bowX, $baseY - 1, $bowZ, $this->blocks->state('minecraft:dragon_head'), true);
        $builder->setWorld($centerX, $baseY + 1, $centerZ, $this->blocks->state('minecraft:brewing_stand'), true);
        $builder->setWorld(
            $centerX + ($alongX ? -3 : 0),
            $baseY + 1,
            $centerZ + ($alongX ? 0 : -3),
            $this->blocks->state('minecraft:chest'),
            true,
        );
        $builder->setWorld($mastX, $baseY + 14, $mastZ, $this->blocks->state('minecraft:end_rod'), true);
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);
        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
