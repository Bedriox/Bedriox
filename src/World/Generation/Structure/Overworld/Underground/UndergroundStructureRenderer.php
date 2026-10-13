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

namespace Bedriox\Server\World\Generation\Structure\Overworld\Underground;

use Bedriox\Server\World\Generation\MutableChunkBuilder;
use Bedriox\Server\World\Generation\SeededNoise;
use Bedriox\Server\World\Generation\Structure\Overworld\Family\StructureCanvas;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureFamily;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureStart;

/** Original bounded underground layouts with chunk-order-independent placement. */
final readonly class UndergroundStructureRenderer
{
    public function __construct(
        private StructureCanvas $canvas,
        private SeededNoise $noise,
    ) {}

    public function render(MutableChunkBuilder $builder, OverworldStructureStart $start, int $baseY): void
    {
        match ($start->family) {
            OverworldStructureFamily::Dungeon => $this->dungeon($builder, $start, $baseY),
            OverworldStructureFamily::Mineshaft => $this->mineshaft($builder, $start, $baseY),
            default => throw new \LogicException('Underground renderer received an unsupported structure family.'),
        };
    }

    private function dungeon(MutableChunkBuilder $builder, OverworldStructureStart $start, int $baseY): void
    {
        $radiusX = 4 + $this->noise->chance($start->x, 0, $start->z, 61_001, 2);
        $radiusZ = 4 + $this->noise->chance($start->x, 1, $start->z, 61_003, 2);
        $this->canvas->hollow(
            $builder,
            $start->x - $radiusX,
            $baseY,
            $start->z - $radiusZ,
            $start->x + $radiusX,
            $baseY + 5,
            $start->z + $radiusZ,
            'minecraft:cobblestone',
        );
        for ($z = $start->z - $radiusZ; $z <= $start->z + $radiusZ; ++$z) {
            for ($x = $start->x - $radiusX; $x <= $start->x + $radiusX; ++$x) {
                if ($this->noise->chance($x, $baseY, $z, 61_007, 5) === 0) {
                    $this->canvas->block($builder, $x, $baseY, $z, 'minecraft:mossy_cobblestone');
                }
            }
        }
        $eastWest = $this->noise->chance($start->x, 2, $start->z, 61_009, 2) === 0;
        for ($height = 1; $height <= 3; ++$height) {
            for ($width = -1; $width <= 1; ++$width) {
                $this->canvas->block(
                    $builder,
                    $start->x + ($eastWest ? $radiusX : $width),
                    $baseY + $height,
                    $start->z + ($eastWest ? $width : $radiusZ),
                    'minecraft:air',
                );
                $this->canvas->block(
                    $builder,
                    $start->x - ($eastWest ? $radiusX : -$width),
                    $baseY + $height,
                    $start->z - ($eastWest ? -$width : $radiusZ),
                    'minecraft:air',
                );
            }
        }
        $this->canvas->block($builder, $start->x, $baseY + 1, $start->z, 'minecraft:mob_spawner');
        $this->canvas->loot($builder, $start->x - $radiusX + 1, $baseY + 1, $start->z + 1, null, 61_101, [
            ['minecraft:bread', 3], ['minecraft:iron_ingot', 4], ['minecraft:wheat', 6], ['minecraft:bone', 5],
        ]);
        if ($this->noise->chance($start->x, 3, $start->z, 61_103, 3) !== 0) {
            $this->canvas->loot($builder, $start->x + $radiusX - 1, $baseY + 1, $start->z - 1, null, 61_107, [
                ['minecraft:coal', 6], ['minecraft:redstone', 5], ['minecraft:bucket', 1], ['minecraft:string', 5],
            ]);
        }
    }

    private function mineshaft(MutableChunkBuilder $builder, OverworldStructureStart $start, int $baseY): void
    {
        $wood = $start->variant === 'badlands' ? 'minecraft:dark_oak_planks' : 'minecraft:oak_planks';
        $beam = $start->variant === 'badlands' ? 'minecraft:dark_oak_log' : 'minecraft:oak_log';
        $this->canvas->hollow($builder, $start->x - 5, $baseY, $start->z - 5, $start->x + 5, $baseY + 6, $start->z + 5, $wood);

        /** @var list<array{int, int, int, int, int}> $frontier */
        $frontier = [[0, 0, 1, 0, 0], [0, 0, -1, 0, 0], [0, 0, 0, 1, 0], [0, 0, 0, -1, 0]];
        $visited = ['0:0:0' => true];
        for ($index = 0; $index < 24 && $frontier !== []; ++$index) {
            [$gridX, $gridZ, $directionX, $directionZ, $level] = array_shift($frontier);
            $nextX = $gridX + $directionX;
            $nextZ = $gridZ + $directionZ;
            $key = $nextX . ':' . $nextZ . ':' . $level;
            if (isset($visited[$key]) || abs($nextX) > 4 || abs($nextZ) > 4) {
                continue;
            }
            $visited[$key] = true;
            $fromX = $start->x + $gridX * 11;
            $fromZ = $start->z + $gridZ * 11;
            $toX = $start->x + $nextX * 11;
            $toZ = $start->z + $nextZ * 11;
            $y = $baseY + $level * 5;
            $this->corridor($builder, $fromX, $fromZ, $toX, $toZ, $y, $wood, $beam);

            if ($index % 5 === 3) {
                $this->canvas->hollow($builder, $toX - 4, $y, $toZ - 4, $toX + 4, $y + 5, $toZ + 4, $wood);
            }
            if ($index === 7 || $index === 16) {
                $this->canvas->loot($builder, $toX + 1, $y + 1, $toZ + 1, null, 62_001 + $index, [
                    ['minecraft:rail', 8], ['minecraft:coal', 6], ['minecraft:iron_ingot', 4], ['minecraft:bread', 3],
                ]);
            }

            $turn = $this->noise->chance($start->x + $nextX, $index, $start->z + $nextZ, 62_101, 3) - 1;
            $leftX = -$directionZ;
            $leftZ = $directionX;
            $frontier[] = [$nextX, $nextZ, $directionX, $directionZ, $level];
            $frontier[] = [$nextX, $nextZ, $turn < 0 ? $leftX : -$leftX, $turn < 0 ? $leftZ : -$leftZ, $level];
            if ($index === 9 && $level === 0) {
                $frontier[] = [$nextX, $nextZ, $directionX, $directionZ, -1];
            }
        }
    }

    private function corridor(
        MutableChunkBuilder $builder,
        int $fromX,
        int $fromZ,
        int $toX,
        int $toZ,
        int $y,
        string $wood,
        string $beam,
    ): void {
        if (!$this->canvas->intersects(
            $builder,
            min($fromX, $toX) - 2,
            min($fromZ, $toZ) - 2,
            max($fromX, $toX) + 2,
            max($fromZ, $toZ) + 2,
        )) {
            return;
        }
        $dx = $toX <=> $fromX;
        $dz = $toZ <=> $fromZ;
        $length = max(abs($toX - $fromX), abs($toZ - $fromZ));
        for ($step = 0; $step <= $length; ++$step) {
            $x = $fromX + $dx * $step;
            $z = $fromZ + $dz * $step;
            for ($side = -1; $side <= 1; ++$side) {
                $sideX = $x + ($dz !== 0 ? $side : 0);
                $sideZ = $z + ($dx !== 0 ? $side : 0);
                $this->canvas->block($builder, $sideX, $y, $sideZ, $wood);
                for ($height = 1; $height <= 3; ++$height) {
                    $this->canvas->block($builder, $sideX, $y + $height, $sideZ, 'minecraft:air');
                }
            }
            $this->canvas->block($builder, $x, $y + 1, $z, 'minecraft:rail');
            if ($step % 5 !== 0) {
                continue;
            }
            for ($height = 1; $height <= 3; ++$height) {
                $this->canvas->block($builder, $x + ($dz !== 0 ? -2 : 0), $y + $height, $z + ($dx !== 0 ? -2 : 0), $beam);
                $this->canvas->block($builder, $x + ($dz !== 0 ? 2 : 0), $y + $height, $z + ($dx !== 0 ? 2 : 0), $beam);
            }
            for ($side = -2; $side <= 2; ++$side) {
                $this->canvas->block($builder, $x + ($dz !== 0 ? $side : 0), $y + 4, $z + ($dx !== 0 ? $side : 0), $wood);
            }
        }
    }
}
