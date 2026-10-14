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

namespace Bedriox\Server\Tests\World\Generation\Vegetation;

use Bedriox\Server\World\Generation\Vegetation\VegetationProfileCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VegetationProfileCatalogTest extends TestCase
{
    #[DataProvider('woodedBiomes')]
    public function testEveryWoodedBiomeHasAProfileWithMultipleSilhouettes(
        string $biome,
        string $expectedProfile,
        int $minimumArchetypes,
    ): void {
        $profile = VegetationProfileCatalog::forBiome($biome);

        self::assertNotNull($profile);
        self::assertSame($expectedProfile, $profile->name);
        self::assertGreaterThanOrEqual($minimumArchetypes, count($profile->archetypes));
        self::assertGreaterThan(0, $profile->densityPermille);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function woodedBiomes(): iterable
    {
        yield 'forest' => ['minecraft:forest', 'forest', 5];
        yield 'forest hills' => ['minecraft:forest_hills', 'forest', 5];
        yield 'flower forest' => ['minecraft:flower_forest', 'flower_forest', 5];
        yield 'birch forest' => ['minecraft:birch_forest', 'birch_forest', 3];
        yield 'old growth birch' => ['minecraft:birch_forest_mutated', 'birch_forest', 3];
        yield 'dark forest' => ['minecraft:roofed_forest', 'dark_forest', 2];
        yield 'pale garden' => ['minecraft:pale_garden', 'pale_garden', 2];
        yield 'dappled forest' => ['minecraft:dappled_forest', 'dappled_forest', 4];
        yield 'taiga' => ['minecraft:taiga', 'taiga', 3];
        yield 'snowy taiga' => ['minecraft:cold_taiga', 'taiga', 3];
        yield 'old growth taiga' => ['minecraft:mega_taiga', 'old_growth_taiga', 2];
        yield 'grove' => ['minecraft:grove', 'taiga', 3];
        yield 'savanna' => ['minecraft:savanna', 'savanna', 2];
        yield 'jungle' => ['minecraft:jungle', 'jungle', 2];
        yield 'bamboo jungle' => ['minecraft:bamboo_jungle', 'bamboo_jungle', 2];
        yield 'mangrove swamp' => ['minecraft:mangrove_swamp', 'mangrove', 2];
        yield 'swamp' => ['minecraft:swampland', 'swamp', 2];
        yield 'cherry grove' => ['minecraft:cherry_grove', 'cherry_grove', 2];
        yield 'wooded mountains' => ['minecraft:extreme_hills_plus_trees', 'wooded_mountains', 3];
        yield 'wooded badlands' => ['minecraft:mesa_plateau_stone', 'wooded_badlands', 2];
        yield 'meadow' => ['minecraft:meadow', 'meadow_trees', 2];
        yield 'plains' => ['minecraft:plains', 'plains_oaks', 3];
        yield 'sunflower plains' => ['minecraft:sunflower_plains', 'plains_oaks', 3];
    }

    public function testTreelessBiomesRemainTreeless(): void
    {
        foreach (['minecraft:desert', 'minecraft:ocean', 'minecraft:river', 'minecraft:ice_plains'] as $biome) {
            self::assertNull(VegetationProfileCatalog::forBiome($biome));
        }
    }
}
