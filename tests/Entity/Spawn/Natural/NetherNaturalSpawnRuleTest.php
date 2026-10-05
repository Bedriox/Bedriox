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
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnContext;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnMedium;
use Bedriox\Server\Entity\Spawn\Natural\NetherNaturalSpawnRule;
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
            self::context(VanillaEntityType::BLAZE, supportBlock: 'minecraft:nether_brick'),
        ));
        self::assertFalse((new NetherNaturalSpawnRule(VanillaEntityType::BLAZE))->allows(
            self::context(VanillaEntityType::BLAZE, supportBlock: 'minecraft:netherrack'),
        ));
        self::assertTrue((new NetherNaturalSpawnRule(VanillaEntityType::PIGLIN_BRUTE))->allows(
            self::context(VanillaEntityType::PIGLIN_BRUTE, supportBlock: 'minecraft:polished_blackstone_bricks'),
        ));
        self::assertTrue((new NetherNaturalSpawnRule(VanillaEntityType::HOGLIN))->allows(
            self::context(VanillaEntityType::HOGLIN, biome: 'minecraft:crimson_forest'),
        ));
        self::assertFalse((new NetherNaturalSpawnRule(VanillaEntityType::HOGLIN))->allows(
            self::context(VanillaEntityType::HOGLIN, dimension: WorldDimension::OVERWORLD),
        ));
    }

    private static function context(
        VanillaEntityType $type,
        WorldDimension $dimension = WorldDimension::NETHER,
        string $biome = 'minecraft:hell',
        NaturalSpawnMedium $medium = NaturalSpawnMedium::GROUND,
        ?string $supportBlock = 'minecraft:netherrack',
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
        );
    }
}
