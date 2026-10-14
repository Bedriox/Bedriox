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

namespace Bedriox\Server\Tests\World\Generation\Noise;

use Bedriox\Server\World\Generation\Noise\OverworldBaseDensitySampler;
use Bedriox\Server\World\Generation\Noise\OverworldDensityLattice;
use PHPUnit\Framework\TestCase;

final class OverworldDensityLatticeTest extends TestCase
{
    public function testCoarseCornersExactlyMatchTheBaseSampler(): void
    {
        $density = new OverworldBaseDensitySampler(12345);
        $lattice = new OverworldDensityLattice($density, null, -16, 32, -64, 96);

        foreach ([[-16, -64, 32], [-4, 0, 44], [0, 96, 48]] as [$x, $y, $z]) {
            self::assertSame($density->sample($x, $y, $z), $lattice->densityAt($x, $y, $z));
        }
    }

    public function testAdjacentChunksHaveIdenticalSharedBorder(): void
    {
        $density = new OverworldBaseDensitySampler(54321);
        $west = new OverworldDensityLattice($density, null, 0, 0, -64, 96);
        $east = new OverworldDensityLattice($density, null, 16, 0, -64, 96);

        for ($z = 0; $z <= 16; ++$z) {
            for ($y = -64; $y <= 96; ++$y) {
                self::assertEqualsWithDelta($west->densityAt(16, $y, $z), $east->densityAt(16, $y, $z), 1.0e-12);
            }
        }
    }

    public function testBatchedColumnMatchesIndividualInterpolation(): void
    {
        $lattice = new OverworldDensityLattice(new OverworldBaseDensitySampler(19), null, 0, 0, -64, 96);
        $column = $lattice->densitiesForColumn(7, 11);

        self::assertCount(161, $column);
        foreach ($column as $offset => $value) {
            self::assertEqualsWithDelta($lattice->densityAt(7, -64 + $offset, 11), $value, 1.0e-12);
        }
    }

    public function testChunkBatchMatchesEveryIndividualColumn(): void
    {
        $lattice = new OverworldDensityLattice(new OverworldBaseDensitySampler(29), null, -16, 32, -64, 96, 8, 16);
        $columns = $lattice->densitiesForChunk();

        self::assertCount(256, $columns);
        for ($z = 0; $z < 16; ++$z) {
            for ($x = 0; $x < 16; ++$x) {
                $expected = $lattice->densitiesForColumn(-16 + $x, 32 + $z);
                self::assertCount(count($expected), $columns[$x + $z * 16]);
                foreach ($expected as $offset => $density) {
                    self::assertEqualsWithDelta($density, $columns[$x + $z * 16][$offset], 1.0e-12);
                }
            }
        }
    }

    public function testSurfaceBatchMatchesEveryIndividualColumn(): void
    {
        $lattice = new OverworldDensityLattice(new OverworldBaseDensitySampler(31), null, -16, 32, -64, 96, 8, 16);
        $heights = $lattice->surfaceHeightsForChunk();

        self::assertCount(256, $heights);
        for ($z = 0; $z < 16; ++$z) {
            for ($x = 0; $x < 16; ++$x) {
                self::assertSame($lattice->surfaceHeight(-16 + $x, 32 + $z), $heights[$x + $z * 16]);
            }
        }
    }

    public function testPointSurfaceInterpolationMatchesChunkLattice(): void
    {
        $density = new OverworldBaseDensitySampler(37);
        $lattice = new OverworldDensityLattice($density, null, -16, 32, 32, 96, 8, 8);

        for ($z = 32; $z < 48; ++$z) {
            for ($x = -16; $x < 0; ++$x) {
                self::assertSame(
                    $lattice->surfaceHeight($x, $z),
                    $density->interpolatedSurfaceHeight($x, $z, -16, 32, 32, 96, 8, 8),
                );
            }
        }
    }
}
