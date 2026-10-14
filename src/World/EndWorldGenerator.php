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
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockEntity\ContainerBlockEntity;
use Bedriox\Server\World\BlockEntity\ContainerInventory;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\Generation\MutableChunkBuilder;
use Bedriox\Server\World\Generation\SeededNoise;
use Bedriox\Server\World\Generation\Structure\End\EndInfrastructureCatalog;
use Bedriox\Server\World\Generation\Structure\End\EndStructureKind;
use Bedriox\Server\World\Generation\Structure\End\EndStructureLocation;
use Bedriox\Server\World\Generation\Structure\End\EndStructurePlan;
use Bedriox\Server\World\Generation\Structure\End\EndStructurePlanner;

/** Deterministic bounded island, landmark, and structure terrain for the built-in End dimension. */
final readonly class EndWorldGenerator implements VersionedWorldGenerator
{
    public const int VERSION = 5;

    private GenerationBlockPalette $blocks;
    private SeededNoise $noise;
    private EndStructurePlanner $structurePlanner;

    public function __construct(int $seed, BlockStateRegistry $states)
    {
        $this->blocks = GenerationBlockPalette::fromRegistry($states);
        $this->noise = new SeededNoise($seed ^ 0x454e4421);
        $this->structurePlanner = new EndStructurePlanner($seed);
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
            blockEntities: $builder->blockEntities(),
        );
    }

    public function defaultSpawn(): SpawnPosition
    {
        return new SpawnPosition(100, 49, 0);
    }

    public function locateStructure(
        EndStructureKind $kind,
        int $fromX = 0,
        int $fromZ = 0,
        int $maximumRegionRadius = 64,
    ): ?EndStructurePlan {
        foreach ($this->structurePlanner->candidates($kind, $fromX, $fromZ, $maximumRegionRadius) as $candidate) {
            if (hypot((float) $candidate->centerX, (float) $candidate->centerZ) >= 1_150.0
                && $this->surfaceAt($candidate->centerX, $candidate->centerZ) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    public function locateStructureLocation(
        EndStructureKind $kind,
        int $fromX = 0,
        int $fromZ = 0,
        int $maximumRegionRadius = 64,
        ?callable $accept = null,
    ): ?EndStructureLocation {
        foreach ($this->structurePlanner->candidates($kind, $fromX, $fromZ, $maximumRegionRadius) as $plan) {
            $surface = $this->surfaceAt($plan->centerX, $plan->centerZ);
            if ($surface === null || ($accept !== null && !$accept($plan->centerX, $plan->centerZ))) {
                continue;
            }
            $baseY = $surface + 1;
            $shipTeleport = null;
            if ($plan->kind === EndStructureKind::CityWithShip) {
                $shipX = $plan->centerX + ($plan->alongX ? 34 : 62);
                $shipZ = $plan->centerZ + ($plan->alongX ? 62 : 34);
                $shipTeleport = new BlockPosition(
                    $shipX + ($plan->alongX ? 0 : 2),
                    $surface + 21,
                    $shipZ + ($plan->alongX ? 2 : 0),
                );
            }

            return new EndStructureLocation(
                $plan,
                $baseY,
                new BlockPosition($plan->centerX, $baseY + 1, $plan->centerZ),
                $shipTeleport,
            );
        }

        return null;
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
        foreach (EndInfrastructureCatalog::pillarDefinitions() as [$centerX, $centerZ, $radius, $height]) {
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
                for ($offsetX = -2; $offsetX <= 2; ++$offsetX) {
                    for ($offsetZ = -2; $offsetZ <= 2; ++$offsetZ) {
                        if (abs($offsetX) === 2 || abs($offsetZ) === 2) {
                            $builder->setWorld($centerX + $offsetX, $height + 4, $centerZ + $offsetZ, $this->blocks->state('minecraft:iron_bars'));
                        }
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
        for ($ring = 4; $ring <= 6; ++$ring) {
            $y = 69 - ($ring - 3);
            for ($offset = -$ring; $offset <= $ring; ++$offset) {
                $builder->setWorld($offset, $y, -$ring, $this->blocks->state('minecraft:end_stone'));
                $builder->setWorld($offset, $y, $ring, $this->blocks->state('minecraft:end_stone'));
                $builder->setWorld(-$ring, $y, $offset, $this->blocks->state('minecraft:end_stone'));
                $builder->setWorld($ring, $y, $offset, $this->blocks->state('minecraft:end_stone'));
            }
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
        foreach ($this->structurePlanner->intersecting($position) as $plan) {
            if (hypot((float) $plan->centerX, (float) $plan->centerZ) < 1_150.0) {
                continue;
            }
            $surface = $this->surfaceAt($plan->centerX, $plan->centerZ);
            if ($surface === null) {
                continue;
            }
            $this->renderEndCity($builder, $plan->centerX, $surface + 1, $plan->centerZ, $plan->alongX, $plan->variant);
            if ($plan->kind === EndStructureKind::CityWithShip) {
                $this->renderEndShip(
                    $builder,
                    $plan->centerX + ($plan->alongX ? 34 : 62),
                    $surface + 20,
                    $plan->centerZ + ($plan->alongX ? 62 : 34),
                    $plan->alongX,
                );
            }
        }
    }

    private function renderEndCity(MutableChunkBuilder $builder, int $centerX, int $baseY, int $centerZ, bool $alongX, int $variant): void
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

        if ($variant >= 2) {
            $towerX = $centerX + ($alongX ? -14 : 0);
            $towerZ = $centerZ + ($alongX ? 0 : -14);
            for ($level = 0; $level < 2; ++$level) {
                $floorY = $baseY + 4 + $level * 7;
                for ($dx = -3; $dx <= 3; ++$dx) {
                    for ($dz = -3; $dz <= 3; ++$dz) {
                        $edge = abs($dx) === 3 || abs($dz) === 3;
                        $builder->setWorld($towerX + $dx, $floorY, $towerZ + $dz, $purpur);
                        for ($dy = 1; $dy <= 5; ++$dy) {
                            $builder->setWorld($towerX + $dx, $floorY + $dy, $towerZ + $dz, $edge ? $pillar : $air);
                        }
                        $builder->setWorld($towerX + $dx, $floorY + 6, $towerZ + $dz, $purpur);
                    }
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

        // Anchor the city into the island and give each deterministic variant a distinct skyline.
        foreach ([[-3, -3], [3, -3], [-3, 3], [3, 3]] as [$dx, $dz]) {
            for ($depth = 1; $depth <= 10; ++$depth) {
                $builder->setWorld($centerX + $dx, $baseY - $depth, $centerZ + $dz, $pillar);
            }
        }
        for ($offset = -4; $offset <= 4; $offset += 2) {
            $builder->setWorld($centerX + $offset, $baseY + 20, $centerZ - 5, $this->blocks->state('minecraft:end_rod'), true);
            $builder->setWorld($centerX + $offset, $baseY + 20, $centerZ + 5, $this->blocks->state('minecraft:end_rod'), true);
        }
        if (($variant & 1) === 1) {
            $secondRoomX = $centerX + ($alongX ? 0 : -28);
            $secondRoomZ = $centerZ + ($alongX ? -28 : 0);
            for ($offset = 6; $offset <= 24; ++$offset) {
                $x = $centerX + ($alongX ? 0 : -$offset);
                $z = $centerZ + ($alongX ? -$offset : 0);
                for ($side = -1; $side <= 1; ++$side) {
                    $builder->setWorld($x + ($alongX ? $side : 0), $baseY + 6, $z + ($alongX ? 0 : $side), $purpur);
                }
            }
            for ($dx = -4; $dx <= 4; ++$dx) {
                for ($dz = -4; $dz <= 4; ++$dz) {
                    $edge = abs($dx) === 4 || abs($dz) === 4;
                    $builder->setWorld($secondRoomX + $dx, $baseY + 6, $secondRoomZ + $dz, $purpur);
                    for ($dy = 1; $dy <= 8; ++$dy) {
                        $builder->setWorld($secondRoomX + $dx, $baseY + 6 + $dy, $secondRoomZ + $dz, $edge ? $pillar : $air);
                    }
                    $builder->setWorld($secondRoomX + $dx, $baseY + 15, $secondRoomZ + $dz, $purpur);
                }
            }
            $builder->setWorld($secondRoomX, $baseY + 16, $secondRoomZ, $this->blocks->state('minecraft:end_rod'));
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

        // A raised forecastle and tapered keel make the ship readable from below and at distance.
        for ($forward = 10; $forward <= 18; ++$forward) {
            $halfWidth = max(1, 4 - intdiv($forward - 10, 3));
            for ($side = -$halfWidth; $side <= $halfWidth; ++$side) {
                $x = $centerX + ($alongX ? $forward : $side);
                $z = $centerZ + ($alongX ? $side : $forward);
                $builder->setWorld($x, $baseY + 2, $z, $purpur, true);
                if (abs($side) === $halfWidth) {
                    $builder->setWorld($x, $baseY + 3, $z, $pillar, true);
                }
            }
        }
        for ($forward = -18; $forward <= 18; ++$forward) {
            $x = $centerX + ($alongX ? $forward : 0);
            $z = $centerZ + ($alongX ? 0 : $forward);
            $depth = 2 + intdiv(18 - abs($forward), 6);
            for ($offset = 1; $offset <= $depth; ++$offset) {
                $builder->setWorld($x, $baseY - $offset, $z, $pillar, true);
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
        for ($spar = -6; $spar <= 6; ++$spar) {
            $builder->setWorld(
                $mastX + ($alongX ? 0 : $spar),
                $baseY + 10,
                $mastZ + ($alongX ? $spar : 0),
                $pillar,
                true,
            );
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
        $chestPosition = new BlockPosition(
            $centerX + ($alongX ? -3 : 0),
            $baseY + 1,
            $centerZ + ($alongX ? 0 : -3),
        );
        $builder->addBlockEntity(new ContainerBlockEntity(
            BlockEntityType::Chest,
            $chestPosition,
            new ContainerInventory(ContainerBlockEntity::STORAGE_SLOT_COUNT, [
                4 => new ContainerItemStack('minecraft:diamond', 2),
                11 => new ContainerItemStack('minecraft:iron_ingot', 7),
                19 => new ContainerItemStack('minecraft:shulker_shell', 1),
                23 => new ContainerItemStack('minecraft:elytra', 1),
            ]),
        ));
        $builder->setWorld($mastX, $baseY + 14, $mastZ, $this->blocks->state('minecraft:end_rod'), true);
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);
        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
