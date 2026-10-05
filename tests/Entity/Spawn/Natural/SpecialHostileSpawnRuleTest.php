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
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Entity\Spawn\Natural\AquaticSpeciesNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\CaveSpiderNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\CreeperNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\EndermanNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\MagmaCubeNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnContext;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnMedium;
use Bedriox\Server\Entity\Spawn\Natural\SlimeNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\SpiderNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\TurtleNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\WitchNaturalSpawnRule;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;
use PHPUnit\Framework\TestCase;

final class SpecialHostileSpawnRuleTest extends TestCase
{
    public function testSurfaceHostilesRequireDarkGroundInTheOverworld(): void
    {
        foreach ([new SpiderNaturalSpawnRule(), new CreeperNaturalSpawnRule(), new WitchNaturalSpawnRule()] as $rule) {
            self::assertTrue($rule->allows(self::context('minecraft:spider', light: 7)));
            self::assertFalse($rule->allows(self::context('minecraft:spider', light: 8)));
            self::assertFalse($rule->allows(self::context('minecraft:spider', light: 7, medium: NaturalSpawnMedium::WATER)));
            self::assertFalse($rule->allows(self::context('minecraft:spider', light: 7, dimension: WorldDimension::NETHER)));
        }
        self::assertFalse((new CaveSpiderNaturalSpawnRule())->allows(self::context('minecraft:cave_spider', light: 0)));
    }

    public function testSlimeAndMagmaCubeUseDimensionAndHeightRules(): void
    {
        $slime = new SlimeNaturalSpawnRule();
        self::assertTrue($slime->allows(self::context('minecraft:slime', y: 39.0, light: 15)));
        self::assertTrue($slime->allows(self::context('minecraft:slime', y: 60.0, light: 7, biome: 'minecraft:swampland')));
        self::assertFalse($slime->allows(self::context('minecraft:slime', y: 60.0, light: 8, biome: 'minecraft:swampland')));
        self::assertFalse($slime->allows(self::context('minecraft:slime', y: 60.0, light: 0, biome: 'minecraft:plains')));

        $magma = new MagmaCubeNaturalSpawnRule();
        self::assertTrue($magma->allows(self::context('minecraft:magma_cube', dimension: WorldDimension::NETHER)));
        self::assertFalse($magma->allows(self::context('minecraft:magma_cube')));
    }

    public function testEndermanAllowsDarkGroundAcrossItsDimensions(): void
    {
        $rule = new EndermanNaturalSpawnRule();
        self::assertTrue($rule->allows(self::context('minecraft:enderman', light: 7)));
        self::assertTrue($rule->allows(self::context('minecraft:enderman', light: 7, dimension: WorldDimension::END)));
        self::assertFalse($rule->allows(self::context('minecraft:enderman', light: 8)));
    }

    public function testAquaticSpeciesUseDistinctBiomeDepthAndStructureRules(): void
    {
        $cod = new AquaticSpeciesNaturalSpawnRule(VanillaEntityType::COD);
        self::assertTrue($cod->allows(self::context(
            VanillaEntityType::COD->value,
            biome: 'minecraft:cold_ocean',
            medium: NaturalSpawnMedium::WATER,
        )));
        self::assertFalse($cod->allows(self::context(
            VanillaEntityType::COD->value,
            biome: 'minecraft:warm_ocean',
            medium: NaturalSpawnMedium::WATER,
        )));

        $tropicalFish = new AquaticSpeciesNaturalSpawnRule(VanillaEntityType::TROPICAL_FISH);
        self::assertTrue($tropicalFish->allows(self::context(
            VanillaEntityType::TROPICAL_FISH->value,
            biome: 'minecraft:lukewarm_ocean',
            medium: NaturalSpawnMedium::WATER,
        )));
        self::assertFalse($tropicalFish->allows(self::context(
            VanillaEntityType::TROPICAL_FISH->value,
            biome: 'minecraft:frozen_ocean',
            medium: NaturalSpawnMedium::WATER,
        )));

        $axolotl = new AquaticSpeciesNaturalSpawnRule(VanillaEntityType::AXOLOTL);
        self::assertTrue($axolotl->allows(self::context(
            VanillaEntityType::AXOLOTL->value,
            y: 40.0,
            biome: 'minecraft:lush_caves',
            medium: NaturalSpawnMedium::WATER,
        )));
        self::assertFalse($axolotl->allows(self::context(
            VanillaEntityType::AXOLOTL->value,
            y: 64.0,
            biome: 'minecraft:lush_caves',
            medium: NaturalSpawnMedium::WATER,
        )));

        self::assertFalse((new AquaticSpeciesNaturalSpawnRule(VanillaEntityType::GUARDIAN))->allows(self::context(
            VanillaEntityType::GUARDIAN->value,
            biome: 'minecraft:deep_ocean',
            medium: NaturalSpawnMedium::WATER,
        )));
    }

    public function testTurtlesUseLitBeachGroundInsteadOfTheAquaticPool(): void
    {
        $rule = new TurtleNaturalSpawnRule();
        self::assertTrue($rule->allows(self::context(
            VanillaEntityType::TURTLE->value,
            light: 9,
            biome: 'minecraft:beach',
        )));
        self::assertFalse($rule->allows(self::context(
            VanillaEntityType::TURTLE->value,
            light: 9,
            biome: 'minecraft:ocean',
            medium: NaturalSpawnMedium::WATER,
        )));
    }

    private static function context(
        string $identifier,
        float $y = 64.0,
        int $light = 0,
        string $biome = 'minecraft:plains',
        WorldDimension $dimension = WorldDimension::OVERWORLD,
        NaturalSpawnMedium $medium = NaturalSpawnMedium::GROUND,
    ): NaturalSpawnContext {
        return new NaturalSpawnContext(
            'world',
            new ChunkPosition(0, 0),
            new VanillaEntityIdentifier($identifier),
            EntityCategory::MONSTER,
            new Position(0.0, $y, 0.0),
            $dimension,
            $biome,
            $medium,
            $light,
            576.0,
            576.0,
        );
    }
}
