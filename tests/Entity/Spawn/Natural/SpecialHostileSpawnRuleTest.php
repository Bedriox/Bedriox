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
use Bedriox\Server\Entity\Spawn\Natural\CaveSpiderNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\CreeperNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\EndermanNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\MagmaCubeNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnContext;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnMedium;
use Bedriox\Server\Entity\Spawn\Natural\SlimeNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\SpiderNaturalSpawnRule;
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
            self::assertFalse($rule->allows(self::context('minecraft:spider', light: 7, dimension: 'minecraft:nether')));
        }
        self::assertFalse((new CaveSpiderNaturalSpawnRule())->allows(self::context('minecraft:cave_spider', light: 0)));
    }

    public function testSlimeAndMagmaCubeUseDimensionAndHeightRules(): void
    {
        $slime = new SlimeNaturalSpawnRule();
        self::assertTrue($slime->allows(self::context('minecraft:slime', y: 39.0, light: 15)));
        self::assertTrue($slime->allows(self::context('minecraft:slime', y: 60.0, light: 7, biome: 'minecraft:swamp')));
        self::assertFalse($slime->allows(self::context('minecraft:slime', y: 60.0, light: 8, biome: 'minecraft:swamp')));
        self::assertFalse($slime->allows(self::context('minecraft:slime', y: 60.0, light: 0, biome: 'minecraft:plains')));

        $magma = new MagmaCubeNaturalSpawnRule();
        self::assertTrue($magma->allows(self::context('minecraft:magma_cube', dimension: 'minecraft:nether')));
        self::assertFalse($magma->allows(self::context('minecraft:magma_cube')));
    }

    public function testEndermanAllowsDarkGroundAcrossItsDimensions(): void
    {
        $rule = new EndermanNaturalSpawnRule();
        self::assertTrue($rule->allows(self::context('minecraft:enderman', light: 7)));
        self::assertTrue($rule->allows(self::context('minecraft:enderman', light: 7, dimension: 'minecraft:the_end')));
        self::assertFalse($rule->allows(self::context('minecraft:enderman', light: 8)));
    }

    private static function context(
        string $identifier,
        float $y = 64.0,
        int $light = 0,
        string $biome = 'minecraft:plains',
        string $dimension = 'minecraft:overworld',
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
