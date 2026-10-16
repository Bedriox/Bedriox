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

use Bedriox\Api\Entity\Value\VillagerProfession;
use Bedriox\Api\Entity\Value\VillagerType;
use Bedriox\Api\Entity\Value\VillagerWorkstation;
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
        $required = [
            'home', 'farm', 'library', 'armorer', 'home', 'fisherman',
            'stable', 'shepherd', 'home', 'fletcher', 'mason', 'butcher',
        ];
        $scheduleOffset = $this->noise->chance($start->x, 0, $start->z, 51_001, count($required));
        $villagerType = VillagerType::fromVillageVariant($start->variant);
        $markers = [
            new PlacedStructureMarker(
                'meeting_golem',
                StructureMarkerType::EntitySpawn,
                'minecraft:iron_golem',
                new StructureCoordinate($start->x, $groundY + 1, $start->z),
                StructureDirection::North,
                ['marker_version' => 1, 'role' => 'meeting_guard', 'structure_x' => $start->x, 'structure_z' => $start->z],
            ),
            new PlacedStructureMarker(
                'meeting_bell',
                StructureMarkerType::PointOfInterest,
                'minecraft:bell',
                new StructureCoordinate($start->x + 3, $groundY + 1, $start->z),
                StructureDirection::North,
                ['marker_version' => 1, 'poi_type' => 'meeting', 'structure_x' => $start->x, 'structure_z' => $start->z],
            ),
        ];
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
            $profession = self::professionForKind($kind);
            $workstation = VillagerWorkstation::forProfession($profession);
            $attributes = [
                'marker_version' => 1,
                'profession' => strtolower($profession->name),
                'villager_type' => strtolower($villagerType->name),
                'structure_x' => $start->x,
                'structure_z' => $start->z,
            ];
            if ($workstation !== null) {
                $coordinate = self::workstationCoordinate($kind, $x, $pieceY, $z, $width, $depth);
                $attributes['workstation'] = $workstation->value;
                $attributes['workstation_x'] = $coordinate->x;
                $attributes['workstation_y'] = $coordinate->y;
                $attributes['workstation_z'] = $coordinate->z;
                $markers[] = new PlacedStructureMarker(
                    'workstation_' . $index,
                    StructureMarkerType::PointOfInterest,
                    $workstation->value,
                    $coordinate,
                    StructureDirection::North,
                    [
                        'marker_version' => 1,
                        'poi_type' => 'workstation',
                        'profession' => strtolower($profession->name),
                        'structure_x' => $start->x,
                        'structure_z' => $start->z,
                    ],
                );
            }
            $markers[] = new PlacedStructureMarker(
                'villager_' . $index,
                StructureMarkerType::EntitySpawn,
                'minecraft:villager_v2',
                new StructureCoordinate($x, $pieceY + 1, $z),
                StructureDirection::North,
                $attributes,
            );
            if ($kind !== 'farm') {
                $bed = self::bedCoordinate($x, $pieceY, $z, $width, $depth);
                $markers[] = new PlacedStructureMarker(
                    'bed_' . $index,
                    StructureMarkerType::PointOfInterest,
                    'minecraft:bed',
                    $bed,
                    StructureDirection::North,
                    ['marker_version' => 1, 'poi_type' => 'bed', 'structure_x' => $start->x, 'structure_z' => $start->z],
                );
            }
            if (in_array($kind, ['armorer', 'library', 'toolsmith', 'weaponsmith'], true)) {
                $markers[] = new PlacedStructureMarker('loot_' . $index, StructureMarkerType::LootContainer, 'bedriox:village_supply', new StructureCoordinate($x + 1, $pieceY + 1, $z + 1), StructureDirection::North, ['building' => $kind]);
            }
        }

        return new VillagePlan($start->variant, $start->x, $groundY, $start->z, $pieces, $markers);
    }

    private static function professionForKind(string $kind): VillagerProfession
    {
        return match ($kind) {
            'farm' => VillagerProfession::FARMER,
            'fisherman' => VillagerProfession::FISHERMAN,
            'shepherd' => VillagerProfession::SHEPHERD,
            'fletcher' => VillagerProfession::FLETCHER,
            'library' => VillagerProfession::LIBRARIAN,
            'cartographer' => VillagerProfession::CARTOGRAPHER,
            'cleric' => VillagerProfession::CLERIC,
            'armorer' => VillagerProfession::ARMORER,
            'weaponsmith' => VillagerProfession::WEAPONSMITH,
            'toolsmith' => VillagerProfession::TOOLSMITH,
            'butcher' => VillagerProfession::BUTCHER,
            'stable' => VillagerProfession::LEATHERWORKER,
            'mason' => VillagerProfession::MASON,
            default => VillagerProfession::UNEMPLOYED,
        };
    }

    private static function workstationCoordinate(
        string $kind,
        int $x,
        int $y,
        int $z,
        int $width,
        int $depth,
    ): StructureCoordinate {
        return match ($kind) {
            'farm' => new StructureCoordinate($x - intdiv($width, 2) + 1, $y + 1, $z),
            'stable' => new StructureCoordinate($x + intdiv($width, 2) - 1, $y + 1, $z - intdiv($depth, 2) + 1),
            default => new StructureCoordinate($x - 2, $y + 1, $z + 1),
        };
    }

    private static function bedCoordinate(int $x, int $y, int $z, int $width, int $depth): StructureCoordinate
    {
        return new StructureCoordinate($x + intdiv($width, 2) - 1, $y + 1, $z + intdiv($depth, 2) - 1);
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
