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

use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Generation\Structure\PlacedStructureMarker;
use Bedriox\Server\World\Generation\Structure\StructureCoordinate;
use Bedriox\Server\World\Generation\Structure\StructureDirection;
use Bedriox\Server\World\Generation\Structure\StructureMarkerType;

final readonly class NetherStructurePlan
{
    public function __construct(
        public string $id,
        public NetherStructureKind $kind,
        public int $centerX,
        public int $baseY,
        public int $centerZ,
        public int $rotation,
        public int $radius,
    ) {
        if ($rotation < 0 || $rotation > 3 || $radius < 1 || $baseY < 1 || $baseY > 126) {
            throw new \InvalidArgumentException('Nether structure plan contains invalid bounded values.');
        }
    }

    public function intersects(ChunkPosition $position): bool
    {
        $minimumX = $position->x * 16;
        $minimumZ = $position->z * 16;

        return $this->centerX + $this->radius >= $minimumX
            && $this->centerX - $this->radius <= $minimumX + 15
            && $this->centerZ + $this->radius >= $minimumZ
            && $this->centerZ - $this->radius <= $minimumZ + 15;
    }

    public function occupiesFloor(int $worldX, int $worldY, int $worldZ): bool
    {
        [$x, $z] = $this->unrotate($worldX - $this->centerX, $worldZ - $this->centerZ);
        $y = $worldY - $this->baseY;

        return match ($this->kind) {
            NetherStructureKind::Fortress => $y === 0 && (
                (abs($x) <= 3 && abs($z) <= 34)
                || (abs($z) <= 3 && abs($x) <= 34)
                || (abs($x) >= 21 && abs($x) <= 33 && abs($z) <= 6)
                || (abs($z) >= 21 && abs($z) <= 33 && abs($x) <= 6)
                || (abs($x) <= 7 && abs($z) <= 7)
            ),
            NetherStructureKind::BastionBridge => $y === 0 && (
                (abs($x) <= 16 && abs($z) <= 3)
                || (abs($x + 25) <= 8 && abs($z) <= 9)
                || (abs($x - 25) <= 8 && abs($z) <= 9)
            ),
            NetherStructureKind::BastionHoglinStable => $y === 0 && abs($x) <= 18 && abs($z) <= 13,
            NetherStructureKind::BastionHousing => ($y === 0 && abs($x + 12) <= 8 && abs($z + 12) <= 8)
                || ($y === 4 && abs($x - 12) <= 8 && abs($z + 12) <= 8)
                || ($y === 7 && abs($x + 12) <= 8 && abs($z - 12) <= 8)
                || ($y === 11 && abs($x - 12) <= 8 && abs($z - 12) <= 8)
                || ($y === 0 && abs($x) <= 12 && abs($z) <= 12 && (abs($x - $z) <= 1 || abs($x + $z) <= 1)),
            NetherStructureKind::BastionTreasure => $y === 0 && abs($x) <= 15 && abs($z) <= 15,
        };
    }

    /** @return list<PlacedStructureMarker> deterministic intent for later population passes */
    public function markers(): array
    {
        $definitions = match ($this->kind) {
            NetherStructureKind::Fortress => [
                ['blaze_spawner', StructureMarkerType::Spawner, 'minecraft:blaze', 27, 1, 0],
                ['fortress_loot', StructureMarkerType::LootContainer, 'bedriox:fortress', 5, 1, 5],
                ['fortress_patrol', StructureMarkerType::EntitySpawn, 'minecraft:wither_skeleton', -27, 1, 0],
            ],
            NetherStructureKind::BastionBridge => [
                ['bridge_loot', StructureMarkerType::LootContainer, 'bedriox:bastion_bridge', 25, 1, 3],
                ['bridge_guard', StructureMarkerType::EntitySpawn, 'minecraft:piglin_brute', -25, 1, 0],
            ],
            NetherStructureKind::BastionHoglinStable => [
                ['stable_loot', StructureMarkerType::LootContainer, 'bedriox:bastion_stable', 14, 1, 0],
                ['stable_hoglin', StructureMarkerType::EntitySpawn, 'minecraft:hoglin', 0, 1, 8],
            ],
            NetherStructureKind::BastionHousing => [
                ['housing_loot', StructureMarkerType::LootContainer, 'bedriox:bastion_housing', -12, 1, -12],
                ['housing_guard', StructureMarkerType::EntitySpawn, 'minecraft:piglin_brute', 0, 1, 0],
            ],
            NetherStructureKind::BastionTreasure => [
                ['treasure_loot', StructureMarkerType::LootContainer, 'bedriox:bastion_treasure', 0, 4, 5],
                ['treasure_guard', StructureMarkerType::EntitySpawn, 'minecraft:piglin_brute', 8, 2, 8],
            ],
        };
        $markers = [];
        foreach ($definitions as [$key, $type, $identifier, $x, $y, $z]) {
            [$x, $z] = $this->rotate($x, $z);
            $markers[] = new PlacedStructureMarker(
                $this->id . ':' . $key,
                $type,
                $identifier,
                new StructureCoordinate($this->centerX + $x, $this->baseY + $y, $this->centerZ + $z),
                match ($this->rotation) {
                    1 => StructureDirection::East,
                    2 => StructureDirection::South,
                    3 => StructureDirection::West,
                    default => StructureDirection::North,
                },
                ['structure' => $this->kind->value],
            );
        }

        return $markers;
    }

    /** @return array{int, int} */
    private function unrotate(int $x, int $z): array
    {
        return match ($this->rotation) {
            1 => [$z, -$x],
            2 => [-$x, -$z],
            3 => [-$z, $x],
            default => [$x, $z],
        };
    }

    /** @return array{int, int} */
    private function rotate(int $x, int $z): array
    {
        return match ($this->rotation) {
            1 => [-$z, $x],
            2 => [-$x, -$z],
            3 => [$z, -$x],
            default => [$x, $z],
        };
    }
}
