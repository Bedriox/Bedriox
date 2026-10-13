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

namespace Bedriox\Server\World\Generation\Structure\Overworld\Village;

use Bedriox\Server\World\Generation\MutableChunkBuilder;
use Bedriox\Server\World\Generation\Structure\Overworld\Family\PlannedStructurePiece;
use Bedriox\Server\World\Generation\Structure\Overworld\Family\StructureCanvas;
use Bedriox\Server\World\Generation\Structure\StructureRotation;

/** Renders original biome-themed settlement pieces with foundations and usable interiors. */
final readonly class VillageRenderer
{
    public function __construct(private StructureCanvas $canvas) {}

    /** @param callable(int, int): int $surfaceAt */
    public function render(MutableChunkBuilder $builder, VillagePlan $plan, callable $surfaceAt): void
    {
        [$wall, $beam, $roof, $door] = match ($plan->variant) {
            'desert' => ['minecraft:sandstone', 'minecraft:cut_sandstone', 'minecraft:smooth_sandstone', 'minecraft:wooden_door'],
            'savanna' => ['minecraft:acacia_planks', 'minecraft:acacia_log', 'minecraft:orange_terracotta', 'minecraft:acacia_door'],
            'taiga', 'snowy' => ['minecraft:spruce_planks', 'minecraft:spruce_log', 'minecraft:cobblestone', 'minecraft:spruce_door'],
            default => ['minecraft:oak_planks', 'minecraft:oak_log', 'minecraft:cobblestone', 'minecraft:wooden_door'],
        };
        foreach ($plan->pieces as $piece) {
            if (str_starts_with($piece->kind, 'road_')) {
                $this->road($builder, $piece, $surfaceAt);
                continue;
            }
            match ($piece->kind) {
                'meeting_square' => $this->square($builder, $piece, $beam, $surfaceAt),
                'farm' => $this->farm($builder, $piece, $beam, $surfaceAt),
                'stable' => $this->stable($builder, $piece, $wall, $beam, $surfaceAt),
                default => $this->building($builder, $piece, $wall, $beam, $roof, $door, $surfaceAt),
            };
        }
        foreach ($plan->markers as $index => $marker) {
            if ($marker->type === \Bedriox\Server\World\Generation\Structure\StructureMarkerType::LootContainer) {
                $this->canvas->loot($builder, $marker->coordinate->x, $marker->coordinate->y, $marker->coordinate->z, null, 51_501 + $index, [
                    ['minecraft:bread', 4], ['minecraft:apple', 3], ['minecraft:iron_ingot', 3], ['minecraft:wheat', 6],
                ]);
            }
        }
    }

    /** @param callable(int, int): int $surfaceAt */
    private function road(MutableChunkBuilder $builder, PlannedStructurePiece $piece, callable $surfaceAt): void
    {
        $rx = intdiv($piece->width, 2);
        $rz = intdiv($piece->depth, 2);
        for ($z = $piece->z - $rz; $z <= $piece->z + $rz; ++$z) {
            for ($x = $piece->x - $rx; $x <= $piece->x + $rx; ++$x) {
                $y = $surfaceAt($x, $z);
                if ($y <= 62) {
                    continue;
                }
                $this->canvas->block($builder, $x, $y, $z, 'minecraft:grass_path');
                $this->canvas->block($builder, $x, $y + 1, $z, 'minecraft:air');
                $this->canvas->block($builder, $x, $y + 2, $z, 'minecraft:air');
            }
        }
    }

    /** @param callable(int, int): int $surfaceAt */
    private function square(MutableChunkBuilder $builder, PlannedStructurePiece $piece, string $beam, callable $surfaceAt): void
    {
        $this->foundation($builder, $piece, 'minecraft:cobblestone', $surfaceAt);
        $this->canvas->floor($builder, $piece->x - 4, $piece->x + 4, $piece->y, $piece->z - 4, $piece->z + 4, 'minecraft:cobblestone');
        $this->canvas->box($builder, $piece->x - 1, $piece->y, $piece->z - 1, $piece->x + 1, $piece->y + 1, $piece->z + 1, 'minecraft:water');
        foreach ([[-3, -3], [-3, 3], [3, -3], [3, 3]] as [$dx, $dz]) {
            $this->canvas->column($builder, $piece->x + $dx, $piece->y + 1, $piece->y + 3, $piece->z + $dz, $beam);
            $this->canvas->block($builder, $piece->x + $dx, $piece->y + 4, $piece->z + $dz, 'minecraft:lantern');
        }
    }

    /** @param callable(int, int): int $surfaceAt */
    private function building(MutableChunkBuilder $builder, PlannedStructurePiece $piece, string $wall, string $beam, string $roof, string $door, callable $surfaceAt): void
    {
        $rx = intdiv($piece->width, 2);
        $rz = intdiv($piece->depth, 2);
        $this->foundation($builder, $piece, 'minecraft:cobblestone', $surfaceAt);
        $this->canvas->hollow($builder, $piece->x - $rx, $piece->y, $piece->z - $rz, $piece->x + $rx, $piece->y + 5, $piece->z + $rz, $wall);
        foreach ([[-$rx, -$rz], [-$rx, $rz], [$rx, -$rz], [$rx, $rz]] as [$dx, $dz]) {
            $this->canvas->column($builder, $piece->x + $dx, $piece->y, $piece->y + 6, $piece->z + $dz, $beam);
        }
        for ($layer = 0; $layer <= 2; ++$layer) {
            $this->canvas->floor($builder, $piece->x - $rx - 1 + $layer, $piece->x + $rx + 1 - $layer, $piece->y + 6 + $layer, $piece->z - $rz - 1, $piece->z + $rz + 1, $roof);
        }
        [$doorX, $doorZ] = match ($piece->rotation) {
            StructureRotation::Clockwise90 => [$piece->x + $rx, $piece->z],
            StructureRotation::Clockwise180 => [$piece->x, $piece->z + $rz],
            StructureRotation::Clockwise270 => [$piece->x - $rx, $piece->z],
            default => [$piece->x, $piece->z - $rz],
        };
        [$direction, $pathX, $pathZ] = match ($piece->rotation) {
            StructureRotation::Clockwise90 => ['east', 1, 0],
            StructureRotation::Clockwise180 => ['south', 0, 1],
            StructureRotation::Clockwise270 => ['west', -1, 0],
            default => ['north', 0, -1],
        };
        foreach ([0, 1] as $upper) {
            $this->canvas->canonicalBlock($builder, $doorX, $piece->y + 1 + $upper, $doorZ, $door, [
                'door_hinge_bit' => 0,
                'minecraft:cardinal_direction' => $direction,
                'open_bit' => 0,
                'upper_block_bit' => $upper,
            ]);
        }
        for ($step = 1; $step <= 12; ++$step) {
            $x = $doorX + $pathX * $step;
            $z = $doorZ + $pathZ * $step;
            $y = $surfaceAt($x, $z);
            if ($y <= 62) {
                break;
            }
            $this->canvas->block($builder, $x, $y, $z, 'minecraft:grass_path');
            $this->canvas->block($builder, $x, $y + 1, $z, 'minecraft:air');
            $this->canvas->block($builder, $x, $y + 2, $z, 'minecraft:air');
        }
        foreach ([[-$rx, 0], [$rx, 0], [0, -$rz], [0, $rz]] as [$windowX, $windowZ]) {
            if ($piece->x + $windowX === $doorX && $piece->z + $windowZ === $doorZ) {
                continue;
            }
            $this->canvas->block($builder, $piece->x + $windowX, $piece->y + 3, $piece->z + $windowZ, 'minecraft:glass_pane');
        }
        if ($piece->kind === 'library') {
            $this->canvas->box($builder, $piece->x - $rx + 1, $piece->y + 1, $piece->z + $rz - 1, $piece->x + $rx - 1, $piece->y + 3, $piece->z + $rz - 1, 'minecraft:bookshelf');
        }
        if ($piece->kind === 'workshop') {
            $this->canvas->block($builder, $piece->x - 2, $piece->y + 1, $piece->z + 1, 'minecraft:furnace');
        }
    }

    /** @param callable(int, int): int $surfaceAt */
    private function farm(MutableChunkBuilder $builder, PlannedStructurePiece $piece, string $beam, callable $surfaceAt): void
    {
        $rx = intdiv($piece->width, 2);
        $rz = intdiv($piece->depth, 2);
        $this->foundation($builder, $piece, 'minecraft:dirt', $surfaceAt);
        for ($x = $piece->x - $rx; $x <= $piece->x + $rx; ++$x) {
            for ($z = $piece->z - $rz; $z <= $piece->z + $rz; ++$z) {
                $this->canvas->block($builder, $x, $piece->y, $z, $x === $piece->x ? 'minecraft:water' : 'minecraft:farmland');
                if ($x !== $piece->x) {
                    $this->canvas->block($builder, $x, $piece->y + 1, $z, 'minecraft:wheat');
                }
            }
        }
        for ($x = $piece->x - $rx; $x <= $piece->x + $rx; ++$x) {
            $this->canvas->block($builder, $x, $piece->y, $piece->z - $rz, $beam);
            $this->canvas->block($builder, $x, $piece->y, $piece->z + $rz, $beam);
        }
        for ($z = $piece->z - $rz; $z <= $piece->z + $rz; ++$z) {
            $this->canvas->block($builder, $piece->x - $rx, $piece->y, $z, $beam);
            $this->canvas->block($builder, $piece->x + $rx, $piece->y, $z, $beam);
        }
    }

    /** @param callable(int, int): int $surfaceAt */
    private function stable(MutableChunkBuilder $builder, PlannedStructurePiece $piece, string $wall, string $beam, callable $surfaceAt): void
    {
        $rx = intdiv($piece->width, 2);
        $rz = intdiv($piece->depth, 2);
        $this->foundation($builder, $piece, 'minecraft:cobblestone', $surfaceAt);
        $this->canvas->floor($builder, $piece->x - $rx, $piece->x + $rx, $piece->y, $piece->z - $rz, $piece->z + $rz, 'minecraft:coarse_dirt');
        foreach ([[-$rx, -$rz], [-$rx, $rz], [$rx, -$rz], [$rx, $rz]] as [$dx, $dz]) {
            $this->canvas->column($builder, $piece->x + $dx, $piece->y, $piece->y + 5, $piece->z + $dz, $beam);
        }
        $this->canvas->floor($builder, $piece->x - $rx - 1, $piece->x + $rx + 1, $piece->y + 6, $piece->z - $rz - 1, $piece->z + $rz + 1, $wall);
        $this->canvas->block($builder, $piece->x - $rx + 1, $piece->y + 1, $piece->z - $rz + 1, 'minecraft:hay_block');
        $this->canvas->block($builder, $piece->x - $rx + 1, $piece->y + 2, $piece->z - $rz + 1, 'minecraft:hay_block');
    }

    /** @param callable(int, int): int $surfaceAt */
    private function foundation(MutableChunkBuilder $builder, PlannedStructurePiece $piece, string $block, callable $surfaceAt): void
    {
        $rx = intdiv($piece->width, 2);
        $rz = intdiv($piece->depth, 2);
        for ($z = $piece->z - $rz; $z <= $piece->z + $rz; ++$z) {
            for ($x = $piece->x - $rx; $x <= $piece->x + $rx; ++$x) {
                $ground = $surfaceAt($x, $z);
                $this->canvas->column($builder, $x, min($ground, $piece->y), $piece->y, $z, $block);
            }
        }
    }
}
