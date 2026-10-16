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

namespace Bedriox\Server\Tests\World\Generation\Structure\Overworld;

use Bedriox\Api\Entity\Value\VillagerProfession;
use Bedriox\Api\Entity\Value\VillagerWorkstation;
use Bedriox\Server\World\Generation\SeededNoise;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureFamily;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureStart;
use Bedriox\Server\World\Generation\Structure\Overworld\Village\VillagePlanner;
use Bedriox\Server\World\Generation\Structure\StructureMarkerType;
use PHPUnit\Framework\TestCase;

final class VillageProfessionPlanTest extends TestCase
{
    public function testVillageResidentsCarryDurableProfessionAndWorkstationAttributes(): void
    {
        $plan = (new VillagePlanner(new SeededNoise(0)))->plan(
            new OverworldStructureStart(OverworldStructureFamily::Village, 'desert', 8, 8, 48),
            static fn(int $x, int $z): int => 70,
        );
        $points = [];
        foreach ($plan->markers as $marker) {
            if ($marker->type === StructureMarkerType::PointOfInterest) {
                $points[$marker->coordinate->x . ':' . $marker->coordinate->y . ':' . $marker->coordinate->z] = $marker;
            }
        }
        $villagers = array_values(array_filter(
            $plan->markers,
            static fn($marker): bool => $marker->type === StructureMarkerType::EntitySpawn
                && $marker->identifier === 'minecraft:villager_v2',
        ));
        self::assertNotEmpty($villagers);
        foreach ($villagers as $villager) {
            self::assertSame(1, $villager->attributes['marker_version']);
            self::assertSame('desert', $villager->attributes['villager_type']);
            $professionName = $villager->attributes['profession'];
            self::assertIsString($professionName);
            $profession = self::profession($professionName);
            $workstation = VillagerWorkstation::forProfession($profession);
            if ($workstation === null) {
                continue;
            }
            self::assertSame($workstation->value, $villager->attributes['workstation']);
            $key = $villager->attributes['workstation_x'] . ':'
                . $villager->attributes['workstation_y'] . ':'
                . $villager->attributes['workstation_z'];
            self::assertArrayHasKey($key, $points);
            self::assertSame($workstation->value, $points[$key]->identifier);
        }
    }

    private static function profession(string $name): VillagerProfession
    {
        foreach (VillagerProfession::cases() as $profession) {
            if (strtolower($profession->name) === $name) {
                return $profession;
            }
        }

        self::fail('Village marker contains an unsupported profession.');
    }
}
