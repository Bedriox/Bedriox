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

namespace Bedriox\Server\Tests\Entity\Spawn\Natural;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\VanillaEntityIdentifier;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Entity\Spawn\Natural\BoggedNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\HuskNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnContext;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnMedium;
use Bedriox\Server\Entity\Spawn\Natural\ParchedNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\StrayNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\WitherSkeletonNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\ZombieVillagerNaturalSpawnRule;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;
use PHPUnit\Framework\TestCase;

final class HostileFamilySpawnRuleTest extends TestCase
{
    public function testBiomeSpecificSurfaceRulesRejectLightAndWrongBiomes(): void
    {
        $husk = new HuskNaturalSpawnRule();
        self::assertTrue($husk->allows(self::context('minecraft:husk', 'minecraft:desert', 7)));
        self::assertTrue($husk->allows(self::context('minecraft:husk', 'minecraft:desert_hills', 7)));
        self::assertFalse($husk->allows(self::context('minecraft:husk', 'minecraft:plains', 7)));
        self::assertFalse($husk->allows(self::context('minecraft:husk', 'minecraft:deserted_plains', 7)));
        self::assertFalse($husk->allows(self::context('minecraft:husk', 'minecraft:desert', 8)));

        $bogged = new BoggedNaturalSpawnRule();
        self::assertTrue($bogged->allows(self::context('minecraft:bogged', 'minecraft:swampland', 7)));
        self::assertTrue($bogged->allows(self::context('minecraft:bogged', 'minecraft:mangrove_swamp', 7)));
        self::assertFalse($bogged->allows(self::context('minecraft:bogged', 'minecraft:desert', 7)));

        $parched = new ParchedNaturalSpawnRule();
        self::assertTrue($parched->allows(self::context('minecraft:parched', 'minecraft:desert', 7)));
        self::assertFalse($parched->allows(self::context('minecraft:parched', 'minecraft:desert', 7, NaturalSpawnMedium::WATER)));
    }

    public function testStrayFrozenOceanHeightAndStructureOnlyRules(): void
    {
        $stray = new StrayNaturalSpawnRule();
        self::assertTrue($stray->allows(self::context('minecraft:stray', 'minecraft:frozen_peaks', 7, y: 100.0)));
        self::assertTrue($stray->allows(self::context('minecraft:stray', 'minecraft:frozen_ocean', 7, y: 64.0)));
        self::assertFalse($stray->allows(self::context('minecraft:stray', 'minecraft:frozen_ocean', 7, y: 59.0)));

        self::assertFalse((new ZombieVillagerNaturalSpawnRule())->allows(
            self::context('minecraft:zombie_villager_v2', 'minecraft:plains', 0),
        ));
        self::assertFalse((new WitherSkeletonNaturalSpawnRule())->allows(
            self::context('minecraft:wither_skeleton', 'minecraft:nether_wastes', 0),
        ));
    }

    private static function context(
        string $identifier,
        string $biome,
        int $light,
        NaturalSpawnMedium $medium = NaturalSpawnMedium::GROUND,
        float $y = 64.0,
    ): NaturalSpawnContext {
        return new NaturalSpawnContext(
            'world',
            new ChunkPosition(0, 0),
            new VanillaEntityIdentifier($identifier),
            EntityCategory::MONSTER,
            new Position(0.0, $y, 0.0),
            WorldDimension::OVERWORLD,
            $biome,
            $medium,
            $light,
            576.0,
            576.0,
        );
    }
}
