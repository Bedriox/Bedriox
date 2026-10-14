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
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tools/WorldGenerationQualification.php';

final class WorldGenerationQualificationTest extends TestCase
{
    public function testQualificationReportIsDeterministicAndCoversEveryQualityDimension(): void
    {
        $qualification = new WorldGenerationQualification();
        $first = $qualification->run(73_511, 1);
        $second = $qualification->run(73_511, 1);
        unset($first['latency_ms'], $second['latency_ms']);

        self::assertSame($first, $second);
        self::assertSame(5, $first['generator_version']);
        self::assertSame(0, $first['center_chunk_x']);
        self::assertSame(0, $first['center_chunk_z']);
        self::assertSame(9, $first['sampled_chunks']);
        self::assertSame(48 * 48, $first['sampled_columns']);
        $determinism = $first['determinism'];
        $terrain = $first['terrain'];
        $geology = $first['surface_geology'];
        $coastline = $first['coastline'];
        $biomes = $first['biome_transitions'];
        $caves = $first['caves'];
        $vegetation = $first['vegetation'];
        $seams = $first['seams'];
        self::assertIsArray($determinism);
        self::assertIsArray($terrain);
        self::assertIsArray($geology);
        self::assertIsArray($coastline);
        self::assertIsArray($biomes);
        self::assertIsArray($caves);
        self::assertIsArray($vegetation);
        self::assertIsArray($seams);
        self::assertTrue($determinism['reverse_order_byte_identical']);
        self::assertArrayHasKey('height_lag_correlation', $terrain);
        self::assertArrayHasKey('maximum_cardinal_contour_run', $terrain);
        self::assertArrayHasKey('isolated_rock_percent', $geology);
        self::assertArrayHasKey('gentle_slope_exposed_rock_percent', $geology);
        self::assertArrayHasKey('material_runs', $geology);
        self::assertArrayHasKey('maximum_cardinal_straight_run', $coastline);
        self::assertArrayHasKey('corner_cell_percent', $coastline);
        self::assertArrayHasKey('orientation_bias', $coastline);
        self::assertArrayHasKey('maximum_boundary_height_delta', $biomes);
        self::assertArrayHasKey('largest_component_percent', $caves);
        self::assertArrayHasKey('entrance_columns', $caves);
        self::assertArrayHasKey('horizontal_opening_columns', $caves);
        self::assertArrayHasKey('horizontal_opening_cells', $caves);
        self::assertArrayHasKey('sinkhole_candidate_columns', $caves);
        self::assertArrayHasKey('fluid_cells_directly_over_air', $caves);
        self::assertArrayHasKey('trees_per_chunk', $vegetation);
        self::assertArrayHasKey('nearest_spacing_mean', $vegetation);
        self::assertArrayHasKey('unique_local_shape_percent', $vegetation);
        self::assertArrayHasKey('foliage_only_components', $vegetation);
        self::assertArrayHasKey('unrooted_log_components', $vegetation);
        self::assertArrayHasKey('tree_cells_in_ocean_columns', $vegetation);
        self::assertArrayHasKey('orphan_tree_cells_in_ocean_columns', $vegetation);
        self::assertArrayHasKey('biome_density', $vegetation);
        self::assertArrayHasKey('forest_clearing_percent', $vegetation);
        self::assertArrayHasKey('maximum_chunk_boundary_delta', $seams);

        $surfaceMaterials = $geology['surface_materials'];
        self::assertIsArray($surfaceMaterials);
        self::assertArrayHasKey('minecraft:grass_block', $surfaceMaterials);
        self::assertGreaterThan(0, $surfaceMaterials['minecraft:grass_block']);
        self::assertGreaterThanOrEqual(
            $caves['horizontal_opening_columns'],
            $caves['entrance_columns'],
        );

        $materialRuns = $geology['material_runs'];
        self::assertIsArray($materialRuns);
        self::assertArrayHasKey('normalized_lag_agreement', $materialRuns);
        self::assertLessThanOrEqual($geology['gentle_slope_cells'], $geology['gentle_slope_exposed_rock_cells']);
        self::assertLessThanOrEqual(48, $coastline['maximum_cardinal_straight_run']);
        self::assertGreaterThanOrEqual(0.0, $coastline['corner_cell_percent']);
        self::assertLessThanOrEqual(100.0, $coastline['corner_cell_percent']);
        self::assertGreaterThanOrEqual(0.0, $coastline['orientation_bias']);
        self::assertLessThanOrEqual(1.0, $coastline['orientation_bias']);
        self::assertLessThanOrEqual(
            $vegetation['tree_block_components'],
            $vegetation['boundary_excluded_tree_components'],
        );
        self::assertLessThanOrEqual(
            $vegetation['orphan_tree_cells'],
            $vegetation['orphan_tree_cells_in_ocean_columns'],
        );
        self::assertLessThanOrEqual(
            $vegetation['forest_sample_cells_8x8'],
            $vegetation['forest_clearing_cells_8x8'],
        );

        $biomeDensity = $vegetation['biome_density'];
        self::assertIsArray($biomeDensity);
        $biomeColumns = 0;
        $biomeRoots = 0;
        foreach ($biomeDensity as $density) {
            self::assertIsArray($density);
            $sampledColumns = $density['sampled_columns'];
            $trunkRoots = $density['trunk_roots'];
            self::assertIsInt($sampledColumns);
            self::assertIsInt($trunkRoots);
            $biomeColumns += $sampledColumns;
            $biomeRoots += $trunkRoots;
        }
        self::assertSame($first['sampled_columns'], $biomeColumns);
        self::assertSame($vegetation['trunk_roots'], $biomeRoots);
    }

    public function testQualificationCanTargetAChunkRegionAwayFromTheOrigin(): void
    {
        $report = (new WorldGenerationQualification())->run(73_511, 1, 12, -9);

        self::assertSame(12, $report['center_chunk_x']);
        self::assertSame(-9, $report['center_chunk_z']);
        self::assertSame(9, $report['sampled_chunks']);
        self::assertSame(48 * 48, $report['sampled_columns']);
        $determinism = $report['determinism'];
        self::assertIsArray($determinism);
        self::assertTrue($determinism['reverse_order_byte_identical']);
    }

    public function testQualificationRejectsAnUnboundedCenterChunk(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Qualification center chunks');

        (new WorldGenerationQualification())->run(0, 1, 1_000_001, 0);
    }
}
