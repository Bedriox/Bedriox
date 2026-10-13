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

namespace Bedriox\Server\Tests\World\Generation\Biome;

use Bedriox\Server\World\Generation\Biome\BiomeVoronoi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BiomeVoronoiTest extends TestCase
{
    /** @param array{x: float, y: float, z: float} $expected */
    #[DataProvider('nativeOffsets')]
    public function testOffsetsMatchNativeReference(
        int $seed,
        int $originX,
        int $originY,
        int $originZ,
        int $sizeY,
        int $index,
        array $expected,
    ): void {
        $offset = BiomeVoronoi::calculateOffsets($seed, $originX, $originY, $originZ, $sizeY)[$index];

        self::assertSame($expected['x'], $offset->x);
        self::assertSame($expected['y'], $offset->y);
        self::assertSame($expected['z'], $offset->z);
    }

    /** @return iterable<string, array{int, int, int, int, int, int, array{x: float, y: float, z: float}}> */
    public static function nativeOffsets(): iterable
    {
        yield 'seed zero, negative origin' => [0, -56, -68, -40, 392, 0, [
            'x' => -0.1372559815645218,
            'y' => 0.09748413413763046,
            'z' => 0.005173981189727783,
        ]];
        yield 'seed zero, next vertical cell' => [0, -56, -68, -40, 392, 1, [
            'x' => 0.10782726854085922,
            'y' => 0.11287733167409897,
            'z' => 0.0505555085837841,
        ]];
        yield 'seed zero, next z cell' => [0, -56, -68, -40, 392, 392, [
            'x' => 0.258744478225708,
            'y' => 0.2746966481208801,
            'z' => -0.039203543215990067,
        ]];
        yield 'seed one' => [1, -4, -4, -4, 4, 0, [
            'x' => -0.2173887938261032,
            'y' => -0.4336492419242859,
            'z' => -0.3802648186683655,
        ]];
        yield 'large nonzero seed' => [0x1234_5678, 11, 23, -37, 3, 0, [
            'x' => 0.3970291018486023,
            'y' => -0.23913587629795074,
            'z' => 0.15983502566814423,
        ]];
    }

    public function testZoomedSelectionMatchesNativeNegativeBoundaryCalls(): void
    {
        $offsets = BiomeVoronoi::calculateOffsets(0, -56, -68, -40, 392);

        self::assertSame(1961, BiomeVoronoi::zoomedCellIndex(-48, -64, -32, $offsets, 1961, 4, 392));
        self::assertSame(3530, BiomeVoronoi::zoomedCellIndex(-48, -60, -32, $offsets, 1961, 4, 392));
        self::assertSame(1962, BiomeVoronoi::zoomedCellIndex(-48, -59, -32, $offsets, 1961, 4, 392));
    }

    public function testOverlappingBuffersAreCoordinateStable(): void
    {
        $small = BiomeVoronoi::calculateOffsets(0, -52, -68, -36, 8);
        $large = BiomeVoronoi::calculateOffsets(0, -56, -68, -40, 392);
        for ($x = 0; $x < 4; ++$x) {
            for ($z = 0; $z < 4; ++$z) {
                for ($y = 0; $y < 8; ++$y) {
                    self::assertEquals(
                        $small[($x * 32) + ($z * 8) + $y],
                        $large[($x * 1568) + ($z * 392) + $y],
                    );
                }
            }
        }
    }
}
