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

use Bedriox\Server\World\Generation\SeededNoise;
use Bedriox\Server\World\Generation\Structure\Overworld\Family\PlannedStructurePiece;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureStart;
use Bedriox\Server\World\Generation\Structure\PlacedStructureMarker;
use Bedriox\Server\World\Generation\Structure\StructureCoordinate;
use Bedriox\Server\World\Generation\Structure\StructureDirection;
use Bedriox\Server\World\Generation\Structure\StructureMarkerType;
use Bedriox\Server\World\Generation\Structure\StructureRotation;

/** Builds a bounded settlement graph from a central square, four roads, and weighted roadside pieces. */
final readonly class VillagePlanner
{
    public function __construct(private SeededNoise $noise) {}

    /** @param callable(int, int): int $surfaceAt */
    public function plan(OverworldStructureStart $start, callable $surfaceAt): VillagePlan
    {
        $groundY = $this->footprintFloor($start->x, $start->z, 9, 9, $surfaceAt)[0];
        $pieces = [new PlannedStructurePiece('meeting_square', $start->x, $groundY, $start->z, 9, 9)];
        foreach ([
            ['road_north', 0, -24, 5, 39, StructureRotation::None],
            ['road_south', 0, 24, 5, 39, StructureRotation::Clockwise180],
            ['road_west', -24, 0, 39, 5, StructureRotation::Clockwise270],
            ['road_east', 24, 0, 39, 5, StructureRotation::Clockwise90],
        ] as [$kind, $dx, $dz, $width, $depth, $rotation]) {
            $pieces[] = new PlannedStructurePiece($kind, $start->x + $dx, $groundY, $start->z + $dz, $width, $depth, $rotation);
        }
        $slots = [
            [-16, -13, StructureRotation::Clockwise90], [14, -14, StructureRotation::Clockwise270],
            [-17, 13, StructureRotation::Clockwise90], [15, 15, StructureRotation::Clockwise270],
            [-8, -28, StructureRotation::Clockwise180], [9, -29, StructureRotation::Clockwise180],
            [-9, 28, StructureRotation::None], [10, 29, StructureRotation::None],
            [-29, -8, StructureRotation::Clockwise90], [-28, 9, StructureRotation::Clockwise90],
            [28, -9, StructureRotation::Clockwise270], [29, 9, StructureRotation::Clockwise270],
        ];
        $required = ['home', 'farm', 'home', 'workshop', 'home', 'farm', 'stable', 'library', 'home', 'workshop', 'home', 'farm'];
        $scheduleOffset = $this->noise->chance($start->x, 0, $start->z, 51_001, count($required));
        $markers = [new PlacedStructureMarker('meeting_golem', StructureMarkerType::EntitySpawn, 'minecraft:iron_golem', new StructureCoordinate($start->x, $groundY + 1, $start->z), StructureDirection::North, ['role' => 'meeting_guard'])];
        foreach ($slots as $index => [$dx, $dz, $rotation]) {
            $kind = $required[($index + $scheduleOffset) % count($required)];
            $width = $kind === 'farm' ? 11 : ($kind === 'stable' ? 9 : 7 + $index % 2 * 2);
            $depth = $kind === 'farm' ? 9 : 7;
            $x = $start->x + $dx;
            $z = $start->z + $dz;
            [$pieceY, $relief] = $this->footprintFloor($x, $z, $width + 2, $depth + 2, $surfaceAt);
            if ($relief > 5 || $pieceY <= 62) {
                continue;
            }
            $pieces[] = new PlannedStructurePiece($kind, $x, $pieceY, $z, $width, $depth, $rotation);
            if ($kind !== 'farm') {
                $markers[] = new PlacedStructureMarker('villager_' . $index, StructureMarkerType::EntitySpawn, 'minecraft:villager_v2', new StructureCoordinate($x, $pieceY + 1, $z), StructureDirection::North, ['role' => $kind]);
            }
            if (in_array($kind, ['workshop', 'library'], true)) {
                $markers[] = new PlacedStructureMarker('loot_' . $index, StructureMarkerType::LootContainer, 'bedriox:village_supply', new StructureCoordinate($x + 1, $pieceY + 1, $z + 1), StructureDirection::North, ['building' => $kind]);
            }
        }

        return new VillagePlan($start->variant, $start->x, $groundY, $start->z, $pieces, $markers);
    }

    /**
     * @param callable(int, int): int $surfaceAt
     * @return array{int, int} floor and relief
     */
    private function footprintFloor(int $centerX, int $centerZ, int $width, int $depth, callable $surfaceAt): array
    {
        $rx = intdiv($width, 2);
        $rz = intdiv($depth, 2);
        $heights = [];
        foreach ([
            [$centerX - $rx, $centerZ - $rz], [$centerX + $rx, $centerZ - $rz],
            [$centerX - $rx, $centerZ + $rz], [$centerX + $rx, $centerZ + $rz],
            [$centerX, $centerZ],
        ] as [$x, $z]) {
            $heights[] = $surfaceAt($x, $z);
        }
        sort($heights, SORT_NUMERIC);

        return [$heights[3], $heights[4] - $heights[0]];
    }
}
