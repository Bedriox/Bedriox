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

namespace Bedriox\Server\World\Generation\Structure\Nether;

use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockEntity\ContainerBlockEntity;
use Bedriox\Server\World\BlockEntity\ContainerInventory;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\Generation\MutableChunkBuilder;

/** Original modular layouts built from reusable, chunk-clipped primitives. */
final readonly class NetherStructureRenderer
{
    public function __construct(private GenerationBlockPalette $blocks) {}

    public function render(MutableChunkBuilder $builder, NetherStructurePlan $plan): void
    {
        match ($plan->kind) {
            NetherStructureKind::Fortress => $this->fortress($builder, $plan),
            NetherStructureKind::BastionBridge => $this->bastionBridge($builder, $plan),
            NetherStructureKind::BastionHoglinStable => $this->bastionStable($builder, $plan),
            NetherStructureKind::BastionHousing => $this->bastionHousing($builder, $plan),
            NetherStructureKind::BastionTreasure => $this->bastionTreasure($builder, $plan),
        };
    }

    private function fortress(MutableChunkBuilder $builder, NetherStructurePlan $plan): void
    {
        $brick = $this->blocks->state('minecraft:nether_brick');
        $air = $this->blocks->state('minecraft:air');
        $this->room($builder, $plan, 0, 0, 7, 7, 7, $brick, $air);
        foreach ([[0, -27], [0, 27], [-27, 0], [27, 0]] as [$x, $z]) {
            $this->bridge($builder, $plan, 0, 0, $x, $z, $brick, 5);
            $this->room($builder, $plan, $x, $z, 6, 6, 6, $brick, $air);
            $this->support($builder, $plan, $x - 4, $z - 4, -18, $brick);
            $this->support($builder, $plan, $x + 4, $z + 4, -18, $brick);
        }
        foreach ([[-20, -20], [20, -20], [-20, 20], [20, 20]] as [$x, $z]) {
            $this->room($builder, $plan, $x, $z, 4, 4, 10, $brick, $air);
            $this->bridge($builder, $plan, $x, $z, $x === -20 ? -27 : 27, $z, $brick, 3);
            $this->support($builder, $plan, $x, $z, -24, $brick);
            for ($corner = -3; $corner <= 3; $corner += 6) {
                $this->pillar($builder, $plan, $x + $corner, $z + $corner, 10, 13, $brick);
            }
        }
        $this->support($builder, $plan, 0, 0, -26, $brick);
        $this->box($builder, $plan, -3, 1, -34, 3, 4, 34, $air);
        $this->box($builder, $plan, -34, 1, -3, 34, 4, 3, $air);
        foreach ([[-5, -5], [5, -5], [-5, 5], [5, 5]] as [$x, $z]) {
            $this->pillar($builder, $plan, $x, $z, 8, 11, $brick);
        }
        $this->set($builder, $plan, 0, 6, 0, $this->blocks->state('minecraft:glowstone'));
        $this->set($builder, $plan, 27, 1, 0, $this->blocks->state('minecraft:mob_spawner'));
        for ($x = -4; $x <= 4; ++$x) {
            for ($z = 25; $z <= 29; ++$z) {
                $this->set($builder, $plan, $x, 0, $z, $this->blocks->state('minecraft:soul_sand'));
                if ((($x + $z) & 1) === 0) {
                    $this->set($builder, $plan, $x, 1, $z, $this->blocks->state('minecraft:nether_wart_block'));
                }
            }
        }
        $this->chest($builder, $plan, 5, 1, 5, [
            new ContainerItemStack('minecraft:nether_wart', 6),
            new ContainerItemStack('minecraft:gold_ingot', 2),
        ]);
    }

    private function bastionBridge(MutableChunkBuilder $builder, NetherStructurePlan $plan): void
    {
        $blackstone = $this->blocks->state('minecraft:polished_blackstone_bricks');
        $air = $this->blocks->state('minecraft:air');
        $this->room($builder, $plan, -25, 0, 8, 9, 12, $blackstone, $air);
        $this->room($builder, $plan, 25, 0, 8, 9, 12, $blackstone, $air);
        $this->bridge($builder, $plan, -16, 0, 16, 0, $blackstone, 7);
        for ($step = -12; $step <= 12; $step += 6) {
            $this->pillar($builder, $plan, $step, -3, -13, 0, $blackstone);
            $this->pillar($builder, $plan, $step, 3, -13, 0, $blackstone);
        }
        $this->box($builder, $plan, -16, 1, -2, 16, 5, 2, $air);
        foreach ([[-25, -7], [-25, 7], [25, -7], [25, 7]] as [$x, $z]) {
            $this->support($builder, $plan, $x, $z, -28, $blackstone);
        }
        for ($x = -12; $x <= 12; $x += 6) {
            $this->pillar($builder, $plan, $x, -3, 1, 4, $blackstone);
            $this->pillar($builder, $plan, $x, 3, 1, 4, $blackstone);
        }
        $this->set($builder, $plan, -25, 1, 0, $this->blocks->state('minecraft:gold_block'));
        $this->chest($builder, $plan, 25, 1, 3, [
            new ContainerItemStack('minecraft:gold_ingot', 5),
            new ContainerItemStack('minecraft:crossbow', 1),
        ]);
    }

    private function bastionStable(MutableChunkBuilder $builder, NetherStructurePlan $plan): void
    {
        $blackstone = $this->blocks->state('minecraft:blackstone');
        $brick = $this->blocks->state('minecraft:polished_blackstone_bricks');
        $air = $this->blocks->state('minecraft:air');
        $this->room($builder, $plan, 0, 0, 18, 13, 9, $brick, $air);
        for ($stall = -12; $stall <= 12; $stall += 6) {
            $this->box($builder, $plan, $stall - 2, 1, -9, $stall + 2, 3, -5, $air);
            $this->box($builder, $plan, $stall - 2, 0, -9, $stall + 2, 0, -5, $blackstone);
            $this->box($builder, $plan, $stall - 2, 1, 5, $stall + 2, 3, 9, $air);
            $this->box($builder, $plan, $stall - 2, 0, 5, $stall + 2, 0, 9, $blackstone);
        }
        $this->box($builder, $plan, -3, 0, -13, 3, 0, 13, $blackstone);
        foreach ([[-16, -11], [16, -11], [-16, 11], [16, 11]] as [$x, $z]) {
            $this->support($builder, $plan, $x, $z, -22, $brick);
        }
        for ($z = -9; $z <= 9; $z += 6) {
            $this->pillar($builder, $plan, -3, $z, 1, 6, $brick);
            $this->pillar($builder, $plan, 3, $z, 1, 6, $brick);
        }
        $this->set($builder, $plan, 0, 1, -10, $this->blocks->state('minecraft:gold_block'));
        $this->chest($builder, $plan, 14, 1, 0, [
            new ContainerItemStack('minecraft:gold_nugget', 12),
            new ContainerItemStack('minecraft:crimson_fungus', 3),
        ]);
    }

    private function bastionHousing(MutableChunkBuilder $builder, NetherStructurePlan $plan): void
    {
        $brick = $this->blocks->state('minecraft:polished_blackstone_bricks');
        $air = $this->blocks->state('minecraft:air');
        foreach ([[-12, -12, 0], [12, -12, 4], [-12, 12, 7], [12, 12, 11]] as [$x, $z, $raise]) {
            $raised = new NetherStructurePlan($plan->id, $plan->kind, $plan->centerX, $plan->baseY + $raise, $plan->centerZ, $plan->rotation, $plan->radius);
            $this->room($builder, $raised, $x, $z, 8, 8, 8, $brick, $air);
        }
        $this->bridge($builder, $plan, -12, -12, 12, 12, $brick, 3);
        $this->bridge($builder, $plan, 12, -12, -12, 12, $brick, 3);
        foreach ([[-18, -18], [18, -18], [-18, 18], [18, 18]] as $index => [$x, $z]) {
            $this->support($builder, $plan, $x, $z, -26 + $index, $brick);
        }
        $this->room($builder, $plan, 0, 0, 5, 5, 15, $brick, $air);
        $this->set($builder, $plan, 0, 14, 0, $this->blocks->state('minecraft:glowstone'));
        $this->chest($builder, $plan, -12, 1, -12, [
            new ContainerItemStack('minecraft:gold_ingot', 4),
            new ContainerItemStack('minecraft:arrow', 9),
        ]);
    }

    private function bastionTreasure(MutableChunkBuilder $builder, NetherStructurePlan $plan): void
    {
        $brick = $this->blocks->state('minecraft:polished_blackstone_bricks');
        $air = $this->blocks->state('minecraft:air');
        $this->room($builder, $plan, 0, 0, 15, 15, 18, $brick, $air);
        for ($level = 0; $level < 3; ++$level) {
            $inset = 4 + $level * 3;
            $this->box($builder, $plan, -15 + $inset, 1 + $level * 4, -15 + $inset, 15 - $inset, 1 + $level * 4, 15 - $inset, $brick);
        }
        $this->box($builder, $plan, -2, 1, -2, 2, 3, 2, $this->blocks->state('minecraft:gold_block'));
        $this->box($builder, $plan, -1, 1, -1, 1, 3, 1, $this->blocks->state('minecraft:lava'));
        foreach ([[-13, -13], [13, -13], [-13, 13], [13, 13]] as [$x, $z]) {
            $this->support($builder, $plan, $x, $z, -30, $brick);
            $this->pillar($builder, $plan, $x, $z, 1, 16, $brick);
        }
        for ($level = 4; $level <= 12; $level += 4) {
            $this->bridge($builder, $plan, -10 + intdiv($level, 2), 8, 10 - intdiv($level, 2), 8, $brick, 3);
        }
        $this->set($builder, $plan, 0, 17, 0, $this->blocks->state('minecraft:glowstone'));
        $this->chest($builder, $plan, 0, 4, 5, [
            new ContainerItemStack('minecraft:gold_ingot', 8),
            new ContainerItemStack('minecraft:obsidian', 3),
        ]);
    }

    private function room(MutableChunkBuilder $builder, NetherStructurePlan $plan, int $x, int $z, int $radiusX, int $radiusZ, int $height, InternalBlockStateId $wall, InternalBlockStateId $air): void
    {
        $bounds = $this->clippedLocalBounds($builder, $plan, $x - $radiusX, $x + $radiusX, $z - $radiusZ, $z + $radiusZ);
        if ($bounds === null) {
            return;
        }
        [$minimumX, $maximumX, $minimumZ, $maximumZ] = $bounds;
        for ($localX = $minimumX; $localX <= $maximumX; ++$localX) {
            for ($localZ = $minimumZ; $localZ <= $maximumZ; ++$localZ) {
                $dx = $localX - $x;
                $dz = $localZ - $z;
                for ($dy = 0; $dy <= $height; ++$dy) {
                    $edge = $dy === 0 || $dy === $height || abs($dx) === $radiusX || abs($dz) === $radiusZ;
                    $this->set($builder, $plan, $localX, $dy, $localZ, $edge ? $wall : $air);
                }
            }
        }
    }

    private function bridge(MutableChunkBuilder $builder, NetherStructurePlan $plan, int $fromX, int $fromZ, int $toX, int $toZ, InternalBlockStateId $state, int $width): void
    {
        $steps = max(abs($toX - $fromX), abs($toZ - $fromZ));
        for ($step = 0; $step <= $steps; ++$step) {
            $x = $fromX + intdiv(($toX - $fromX) * $step, max(1, $steps));
            $z = $fromZ + intdiv(($toZ - $fromZ) * $step, max(1, $steps));
            for ($side = -intdiv($width, 2); $side <= intdiv($width, 2); ++$side) {
                $targetX = $x + ($fromX === $toX ? $side : 0);
                $targetZ = $z + ($fromZ === $toZ ? $side : 0);
                $this->set($builder, $plan, $targetX, 0, $targetZ, $state);
                for ($y = 1; $y <= 4; ++$y) {
                    $this->set($builder, $plan, $targetX, $y, $targetZ, $this->blocks->state('minecraft:air'));
                }
            }
        }
    }

    private function pillar(MutableChunkBuilder $builder, NetherStructurePlan $plan, int $x, int $z, int $minimumY, int $maximumY, InternalBlockStateId $state): void
    {
        for ($y = $minimumY; $y <= $maximumY; ++$y) {
            $this->set($builder, $plan, $x, $y, $z, $state);
        }
    }

    private function support(MutableChunkBuilder $builder, NetherStructurePlan $plan, int $x, int $z, int $minimumY, InternalBlockStateId $state): void
    {
        for ($y = -1; $y >= $minimumY; --$y) {
            $radius = $y > -5 ? 1 : 0;
            for ($offsetX = -$radius; $offsetX <= $radius; ++$offsetX) {
                for ($offsetZ = -$radius; $offsetZ <= $radius; ++$offsetZ) {
                    $this->set($builder, $plan, $x + $offsetX, $y, $z + $offsetZ, $state);
                }
            }
        }
    }

    private function box(MutableChunkBuilder $builder, NetherStructurePlan $plan, int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ, InternalBlockStateId $state): void
    {
        $bounds = $this->clippedLocalBounds($builder, $plan, $minX, $maxX, $minZ, $maxZ);
        if ($bounds === null) {
            return;
        }
        [$minX, $maxX, $minZ, $maxZ] = $bounds;
        for ($x = $minX; $x <= $maxX; ++$x) {
            for ($y = $minY; $y <= $maxY; ++$y) {
                for ($z = $minZ; $z <= $maxZ; ++$z) {
                    $this->set($builder, $plan, $x, $y, $z, $state);
                }
            }
        }
    }

    /** @return null|array{int, int, int, int} local min X, max X, min Z, max Z */
    private function clippedLocalBounds(
        MutableChunkBuilder $builder,
        NetherStructurePlan $plan,
        int $minimumX,
        int $maximumX,
        int $minimumZ,
        int $maximumZ,
    ): ?array {
        $chunkMinimumX = $builder->position->x * 16 - $plan->centerX;
        $chunkMaximumX = $chunkMinimumX + 15;
        $chunkMinimumZ = $builder->position->z * 16 - $plan->centerZ;
        $chunkMaximumZ = $chunkMinimumZ + 15;
        $corners = [
            $this->unrotate($chunkMinimumX, $chunkMinimumZ, $plan->rotation),
            $this->unrotate($chunkMinimumX, $chunkMaximumZ, $plan->rotation),
            $this->unrotate($chunkMaximumX, $chunkMinimumZ, $plan->rotation),
            $this->unrotate($chunkMaximumX, $chunkMaximumZ, $plan->rotation),
        ];
        $chunkLocalX = array_column($corners, 0);
        $chunkLocalZ = array_column($corners, 1);
        $minimumX = max($minimumX, min($chunkLocalX));
        $maximumX = min($maximumX, max($chunkLocalX));
        $minimumZ = max($minimumZ, min($chunkLocalZ));
        $maximumZ = min($maximumZ, max($chunkLocalZ));

        return $minimumX > $maximumX || $minimumZ > $maximumZ
            ? null
            : [$minimumX, $maximumX, $minimumZ, $maximumZ];
    }

    private function set(MutableChunkBuilder $builder, NetherStructurePlan $plan, int $x, int $y, int $z, InternalBlockStateId $state): void
    {
        [$rotatedX, $rotatedZ] = $this->rotate($x, $z, $plan->rotation);
        $builder->setWorld($plan->centerX + $rotatedX, $plan->baseY + $y, $plan->centerZ + $rotatedZ, $state);
    }

    /** @param list<ContainerItemStack> $loot */
    private function chest(MutableChunkBuilder $builder, NetherStructurePlan $plan, int $x, int $y, int $z, array $loot): void
    {
        [$rotatedX, $rotatedZ] = $this->rotate($x, $z, $plan->rotation);
        $position = new BlockPosition($plan->centerX + $rotatedX, $plan->baseY + $y, $plan->centerZ + $rotatedZ);
        $builder->setWorld($position->x, $position->y, $position->z, $this->blocks->state('minecraft:chest'));
        $contents = [];
        foreach ($loot as $index => $stack) {
            $slot = hexdec(substr(hash('sha256', $plan->id . ':loot:' . $index), 0, 4)) % ContainerBlockEntity::STORAGE_SLOT_COUNT;
            while (isset($contents[$slot])) {
                $slot = ($slot + 1) % ContainerBlockEntity::STORAGE_SLOT_COUNT;
            }
            $contents[$slot] = $stack;
        }
        $builder->addBlockEntity(new ContainerBlockEntity(
            BlockEntityType::Chest,
            $position,
            new ContainerInventory(ContainerBlockEntity::STORAGE_SLOT_COUNT, $contents),
        ));
    }

    /** @return array{int, int} */
    private function rotate(int $x, int $z, int $rotation): array
    {
        return match ($rotation) {
            1 => [-$z, $x],
            2 => [-$x, -$z],
            3 => [$z, -$x],
            default => [$x, $z],
        };
    }

    /** @return array{int, int} */
    private function unrotate(int $x, int $z, int $rotation): array
    {
        return match ($rotation) {
            1 => [$z, -$x],
            2 => [-$x, -$z],
            3 => [-$z, $x],
            default => [$x, $z],
        };
    }
}
