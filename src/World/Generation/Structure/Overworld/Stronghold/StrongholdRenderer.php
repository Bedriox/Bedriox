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

namespace Bedriox\Server\World\Generation\Structure\Overworld\Stronghold;

use Bedriox\Server\World\BlockEntity\MobSpawnerBlockEntity;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Generation\MutableChunkBuilder;
use Bedriox\Server\World\Generation\Structure\Overworld\Family\PlannedStructurePiece;
use Bedriox\Server\World\Generation\Structure\Overworld\Family\StructureCanvas;

/** Renders modular rooms and joins their connector-facing openings. */
final readonly class StrongholdRenderer
{
    public function __construct(private StructureCanvas $canvas) {}

    public function render(MutableChunkBuilder $builder, StrongholdPlan $plan): void
    {
        foreach ($plan->pieces as $piece) {
            $this->room($builder, $piece);
        }
        foreach ($plan->markers as $index => $marker) {
            if ($marker->type === \Bedriox\Server\World\Generation\Structure\StructureMarkerType::LootContainer) {
                $this->canvas->loot($builder, $marker->coordinate->x, $marker->coordinate->y, $marker->coordinate->z, null, 52_501 + $index, [
                    ['minecraft:book', 4], ['minecraft:paper', 7], ['minecraft:ender_pearl', 2], ['minecraft:iron_ingot', 4],
                ]);
            } elseif ($marker->type === \Bedriox\Server\World\Generation\Structure\StructureMarkerType::Spawner) {
                $this->canvas->block($builder, $marker->coordinate->x, $marker->coordinate->y, $marker->coordinate->z, 'minecraft:mob_spawner');
                $builder->addBlockEntity(MobSpawnerBlockEntity::create(
                    new BlockPosition($marker->coordinate->x, $marker->coordinate->y, $marker->coordinate->z),
                    $marker->identifier,
                ));
            } elseif ($marker->type === \Bedriox\Server\World\Generation\Structure\StructureMarkerType::PointOfInterest) {
                $this->portal($builder, $marker->coordinate->x, $marker->coordinate->y, $marker->coordinate->z);
            }
        }
    }

    private function room(MutableChunkBuilder $builder, PlannedStructurePiece $piece): void
    {
        $radius = intdiv($piece->width, 2);
        $height = match ($piece->kind) {
            'library' => 9,
            'portal_room' => 8,
            'spiral_stair' => 11,
            default => 6,
        };
        $wall = match (($piece->x + $piece->z) % 5) {
            0 => 'minecraft:mossy_stone_bricks',
            1 => 'minecraft:cracked_stone_bricks',
            default => 'minecraft:stone_bricks',
        };
        $this->canvas->hollow($builder, $piece->x - $radius, $piece->y, $piece->z - $radius, $piece->x + $radius, $piece->y + $height, $piece->z + $radius, $wall);
        foreach ([[0, -$radius], [0, $radius], [-$radius, 0], [$radius, 0]] as [$dx, $dz]) {
            for ($y = $piece->y + 1; $y <= $piece->y + 3; ++$y) {
                $this->canvas->block($builder, $piece->x + $dx, $y, $piece->z + $dz, 'minecraft:air');
                $this->canvas->block(
                    $builder,
                    $piece->x + $dx + ($dx <=> 0),
                    $y,
                    $piece->z + $dz + ($dz <=> 0),
                    'minecraft:air',
                );
            }
        }
        match ($piece->kind) {
            'library' => $this->library($builder, $piece, $radius),
            'prison' => $this->prison($builder, $piece, $radius),
            'storage' => $this->storage($builder, $piece),
            'spiral_stair', 'start_stair' => $this->stairs($builder, $piece, $radius),
            'portal_room' => $this->portalRoom($builder, $piece, $radius),
            default => null,
        };
    }

    private function library(MutableChunkBuilder $builder, PlannedStructurePiece $piece, int $radius): void
    {
        for ($y = $piece->y + 1; $y <= $piece->y + 6; ++$y) {
            $this->canvas->box($builder, $piece->x - $radius + 1, $y, $piece->z - $radius + 1, $piece->x + $radius - 1, $y, $piece->z - $radius + 1, 'minecraft:bookshelf');
            $this->canvas->box($builder, $piece->x - $radius + 1, $y, $piece->z + $radius - 1, $piece->x + $radius - 1, $y, $piece->z + $radius - 1, 'minecraft:bookshelf');
        }
    }

    private function prison(MutableChunkBuilder $builder, PlannedStructurePiece $piece, int $radius): void
    {
        for ($x = $piece->x - $radius + 2; $x <= $piece->x + $radius - 2; $x += 3) {
            $this->canvas->column($builder, $x, $piece->y + 1, $piece->y + 4, $piece->z, 'minecraft:iron_bars');
        }
    }

    private function storage(MutableChunkBuilder $builder, PlannedStructurePiece $piece): void
    {
        $this->canvas->floor($builder, $piece->x - 2, $piece->x + 2, $piece->y + 1, $piece->z - 2, $piece->z + 2, 'minecraft:oak_planks');
    }

    private function stairs(MutableChunkBuilder $builder, PlannedStructurePiece $piece, int $radius): void
    {
        for ($step = 0; $step < min(8, $radius * 2); ++$step) {
            $this->canvas->block($builder, $piece->x - $radius + 1 + $step, $piece->y + 1 + intdiv($step, 2), $piece->z, 'minecraft:smooth_stone');
        }
    }

    private function portalRoom(MutableChunkBuilder $builder, PlannedStructurePiece $piece, int $radius): void
    {
        $this->canvas->floor($builder, $piece->x - $radius + 2, $piece->x + $radius - 2, $piece->y + 1, $piece->z - $radius + 2, $piece->z + $radius - 2, 'minecraft:chiseled_stone_bricks');
    }

    private function portal(MutableChunkBuilder $builder, int $x, int $y, int $z): void
    {
        for ($offset = -1; $offset <= 1; ++$offset) {
            $this->portalFrame($builder, $x + $offset, $y, $z - 2, 'south');
            $this->portalFrame($builder, $x + $offset, $y, $z + 2, 'north');
            $this->portalFrame($builder, $x - 2, $y, $z + $offset, 'east');
            $this->portalFrame($builder, $x + 2, $y, $z + $offset, 'west');
        }
    }

    private function portalFrame(MutableChunkBuilder $builder, int $x, int $y, int $z, string $direction): void
    {
        $this->canvas->canonicalBlock($builder, $x, $y, $z, 'minecraft:end_portal_frame', [
            'end_portal_eye_bit' => 0,
            'minecraft:cardinal_direction' => $direction,
        ]);
    }
}
