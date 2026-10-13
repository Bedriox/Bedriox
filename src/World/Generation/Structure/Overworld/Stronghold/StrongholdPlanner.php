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

use Bedriox\Server\World\Generation\SeededNoise;
use Bedriox\Server\World\Generation\Structure\Overworld\Family\PlannedStructurePiece;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureStart;
use Bedriox\Server\World\Generation\Structure\PlacedStructureMarker;
use Bedriox\Server\World\Generation\Structure\StructureCoordinate;
use Bedriox\Server\World\Generation\Structure\StructureDirection;
use Bedriox\Server\World\Generation\Structure\StructureMarkerType;
use Bedriox\Server\World\Generation\Structure\StructureRotation;

/** Deterministic connector walk producing a bounded modular underground complex. */
final readonly class StrongholdPlanner
{
    public function __construct(private SeededNoise $noise) {}

    public function plan(OverworldStructureStart $start, int $baseY): StrongholdPlan
    {
        $pieces = [new PlannedStructurePiece('start_stair', $start->x, $baseY, $start->z, 9, 9)];
        $markers = [];
        $occupied = ['0:0' => true];
        /** @var list<array{int, int, int, int}> $frontier */
        $frontier = [[0, -1, 0, -1], [1, 0, 1, 0], [0, 1, 0, 1], [-1, 0, -1, 0]];
        $portalPlaced = false;
        for ($index = 0; $index < 28 && $frontier !== []; ++$index) {
            [$gridX, $gridZ, $directionX, $directionZ] = array_shift($frontier);
            $key = $gridX . ':' . $gridZ;
            if (isset($occupied[$key]) || abs($gridX) > 5 || abs($gridZ) > 5) {
                continue;
            }
            $occupied[$key] = true;
            $distance = abs($gridX) + abs($gridZ);
            $roll = $this->noise->chance($start->x + $gridX, $index, $start->z + $gridZ, 52_001, 100);
            $kind = match (true) {
                !$portalPlaced && $distance >= 5 && $roll < 24 => 'portal_room',
                $roll < 14 => 'library',
                $roll < 28 => 'prison',
                $roll < 42 => 'storage',
                $roll < 58 => 'crossing',
                $roll < 70 => 'spiral_stair',
                default => 'corridor',
            };
            if ($kind === 'portal_room') {
                $portalPlaced = true;
            }
            $x = $start->x + $gridX * 10;
            $z = $start->z + $gridZ * 10;
            $y = $baseY + (($gridX * 3 + $gridZ * 5) % 3) * 2;
            $rotation = self::rotation($directionX, $directionZ);
            $size = in_array($kind, ['library', 'portal_room', 'crossing'], true) ? 13 : 9;
            $pieces[] = new PlannedStructurePiece($kind, $x, $y, $z, $size, $size, $rotation);
            if ($kind === 'library' || $kind === 'storage') {
                $markers[] = new PlacedStructureMarker('loot_' . $index, StructureMarkerType::LootContainer, 'bedriox:stronghold_cache', new StructureCoordinate($x + 2, $y + 1, $z + 2), StructureDirection::North, ['room' => $kind]);
            }
            if ($kind === 'portal_room') {
                $markers[] = new PlacedStructureMarker('silverfish_' . $index, StructureMarkerType::Spawner, 'minecraft:silverfish', new StructureCoordinate($x, $y + 1, $z - 3), StructureDirection::North, []);
                $markers[] = new PlacedStructureMarker('portal_' . $index, StructureMarkerType::PointOfInterest, 'minecraft:end_portal', new StructureCoordinate($x, $y + 1, $z + 2), StructureDirection::North, []);
            }
            $branches = $kind === 'corridor' ? 1 : ($kind === 'crossing' ? 3 : 2);
            foreach (self::directions($directionX, $directionZ, $branches) as [$nextX, $nextZ]) {
                $frontier[] = [$gridX + $nextX, $gridZ + $nextZ, $nextX, $nextZ];
            }
        }
        if (!$portalPlaced) {
            $pieces[] = new PlannedStructurePiece('portal_room', $start->x + 50, $baseY, $start->z, 13, 13, StructureRotation::Clockwise90);
            $markers[] = new PlacedStructureMarker('silverfish_fallback', StructureMarkerType::Spawner, 'minecraft:silverfish', new StructureCoordinate($start->x + 50, $baseY + 1, $start->z - 3), StructureDirection::North, []);
            $markers[] = new PlacedStructureMarker('portal_fallback', StructureMarkerType::PointOfInterest, 'minecraft:end_portal', new StructureCoordinate($start->x + 50, $baseY + 1, $start->z + 2), StructureDirection::North, []);
        }

        return new StrongholdPlan($start->x, $baseY, $start->z, $pieces, $markers);
    }

    /** @return list<array{int, int}> */
    private static function directions(int $forwardX, int $forwardZ, int $count): array
    {
        $left = [-$forwardZ, $forwardX];
        $right = [$forwardZ, -$forwardX];

        return match ($count) {
            1 => [[$forwardX, $forwardZ]],
            2 => [[$forwardX, $forwardZ], $left],
            default => [[$forwardX, $forwardZ], $left, $right],
        };
    }

    private static function rotation(int $x, int $z): StructureRotation
    {
        return match (true) {
            $x > 0 => StructureRotation::Clockwise90,
            $x < 0 => StructureRotation::Clockwise270,
            $z > 0 => StructureRotation::Clockwise180,
            default => StructureRotation::None,
        };
    }
}
