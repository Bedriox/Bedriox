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

namespace Bedriox\Server\Tests\World;

use Bedriox\Server\Tools\WorldGenerationQualification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tools/WorldGenerationQualification.php';

final class WorldGenerationVisualRegressionTest extends TestCase
{
    /** @return iterable<string, array{int, int, string, int}> */
    public static function woodedRegions(): iterable
    {
        yield 'forest hills' => [0, -12, 'minecraft:forest_hills', 12];
        yield 'old growth taiga coast' => [0, 0, 'minecraft:mega_taiga', 4];
        yield 'jungle lowland' => [60, 0, 'minecraft:jungle', 12];
        yield 'swamp transition' => [-20, -8, 'minecraft:swampland', 1];
    }

    #[DataProvider('woodedRegions')]
    public function testReviewedWoodedRegionsHaveGroundedTreesAndNaturalSurfaces(
        int $chunkX,
        int $chunkZ,
        string $biome,
        int $minimumRoots,
    ): void {
        $report = (new WorldGenerationQualification())->run(0, 1, $chunkX, $chunkZ);
        $vegetation = $report['vegetation'];
        $geology = $report['surface_geology'];
        self::assertIsArray($vegetation);
        self::assertIsArray($geology);
        self::assertSame(0, $vegetation['foliage_only_components']);
        self::assertSame(0, $vegetation['unrooted_log_components']);
        self::assertSame(0, $vegetation['orphan_tree_cells']);
        self::assertSame(0, $vegetation['orphan_tree_cells_in_ocean_columns']);
        self::assertLessThanOrEqual(5.0, $geology['gentle_slope_exposed_rock_percent']);

        $biomeDensity = $vegetation['biome_density'];
        self::assertIsArray($biomeDensity);
        self::assertArrayHasKey($biome, $biomeDensity);
        $density = $biomeDensity[$biome];
        self::assertIsArray($density);
        self::assertGreaterThanOrEqual($minimumRoots, $density['trunk_roots']);
        if ($biome === 'minecraft:swampland') {
            $materials = $geology['surface_materials'];
            self::assertIsArray($materials);
            self::assertGreaterThan(100, $materials['minecraft:mud'] ?? 0);
            $coastline = $report['coastline'];
            self::assertIsArray($coastline);
            self::assertGreaterThan(20, $coastline['boundary_edges']);
            self::assertLessThanOrEqual(24, $coastline['maximum_cardinal_straight_run']);
        }
    }
}
