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
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Entity\Spawn\Natural\MagmaCubeNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnContext;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnMedium;
use Bedriox\Server\Entity\Spawn\Natural\NetherNaturalSpawnRule;
use Bedriox\Server\Entity\Spawn\Natural\NetherStructureType;
use Bedriox\Server\Entity\Spawn\Natural\WitherSkeletonNaturalSpawnRule;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;
use PHPUnit\Framework\TestCase;

final class NetherNaturalSpawnRuleTest extends TestCase
{
    public function testNetherFamiliesRequireTheirAuthoritativeEnvironment(): void
    {
        self::assertTrue((new NetherNaturalSpawnRule(VanillaEntityType::STRIDER))->allows(
            self::context(VanillaEntityType::STRIDER, medium: NaturalSpawnMedium::LAVA),
        ));
        self::assertFalse((new NetherNaturalSpawnRule(VanillaEntityType::STRIDER))->allows(
            self::context(VanillaEntityType::STRIDER),
        ));
        self::assertTrue((new NetherNaturalSpawnRule(VanillaEntityType::BLAZE))->allows(
            self::context(VanillaEntityType::BLAZE, structure: NetherStructureType::FORTRESS),
        ));
        self::assertFalse((new NetherNaturalSpawnRule(VanillaEntityType::BLAZE))->allows(
            self::context(VanillaEntityType::BLAZE, supportBlock: 'minecraft:nether_brick'),
        ));
        self::assertFalse((new NetherNaturalSpawnRule(VanillaEntityType::PIGLIN_BRUTE))->allows(
            self::context(VanillaEntityType::PIGLIN_BRUTE, structure: NetherStructureType::BASTION),
        ));
        self::assertTrue((new NetherNaturalSpawnRule(VanillaEntityType::GHAST))->allows(
            self::context(VanillaEntityType::GHAST, medium: NaturalSpawnMedium::AIR),
        ));
        self::assertFalse((new NetherNaturalSpawnRule(VanillaEntityType::GHAST))->allows(
            self::context(VanillaEntityType::GHAST),
        ));
        self::assertTrue((new NetherNaturalSpawnRule(VanillaEntityType::HOGLIN))->allows(
            self::context(VanillaEntityType::HOGLIN, biome: 'minecraft:crimson_forest'),
        ));
        self::assertFalse((new NetherNaturalSpawnRule(VanillaEntityType::HOGLIN))->allows(
            self::context(VanillaEntityType::HOGLIN, dimension: WorldDimension::OVERWORLD),
        ));
        self::assertTrue((new WitherSkeletonNaturalSpawnRule())->allows(
            self::context(VanillaEntityType::WITHER_SKELETON, structure: NetherStructureType::FORTRESS),
        ));
        self::assertFalse((new WitherSkeletonNaturalSpawnRule())->allows(
            self::context(VanillaEntityType::WITHER_SKELETON, supportBlock: 'minecraft:nether_brick'),
        ));
        self::assertTrue((new MagmaCubeNaturalSpawnRule())->allows(
            self::context(VanillaEntityType::MAGMA_CUBE, biome: 'minecraft:basalt_deltas'),
        ));
        self::assertTrue((new MagmaCubeNaturalSpawnRule())->allows(
            self::context(
                VanillaEntityType::MAGMA_CUBE,
                biome: 'minecraft:crimson_forest',
                structure: NetherStructureType::FORTRESS,
            ),
        ));
        self::assertFalse((new MagmaCubeNaturalSpawnRule())->allows(
            self::context(VanillaEntityType::MAGMA_CUBE, biome: 'minecraft:crimson_forest'),
        ));
    }

    private static function context(
        VanillaEntityType $type,
        WorldDimension $dimension = WorldDimension::NETHER,
        string $biome = 'minecraft:hell',
        NaturalSpawnMedium $medium = NaturalSpawnMedium::GROUND,
        ?string $supportBlock = 'minecraft:netherrack',
        ?NetherStructureType $structure = null,
    ): NaturalSpawnContext {
        return new NaturalSpawnContext(
            'nether',
            new ChunkPosition(0, 0),
            $type,
            EntityCategory::MONSTER,
            new Position(0.0, 64.0, 0.0),
            $dimension,
            $biome,
            $medium,
            0,
            576.0,
            576.0,
            $supportBlock,
            $structure,
        );
    }
}
