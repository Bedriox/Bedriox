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

namespace Bedriox\Server\Tests\World\Generation\Surface;

use Bedriox\Server\World\Generation\OverworldClimate;
use Bedriox\Server\World\Generation\OverworldTerrainSample;
use Bedriox\Server\World\Generation\SeededNoise;
use Bedriox\Server\World\Generation\Surface\OverworldSurfaceContext;
use Bedriox\Server\World\Generation\Surface\OverworldSurfaceField;
use Bedriox\Server\World\Generation\Surface\OverworldSurfaceMaterial;
use PHPUnit\Framework\TestCase;

final class OverworldSurfaceFieldTest extends TestCase
{
    public function testClassificationIsDeterministicAndMasksRemainContinuous(): void
    {
        $field = new OverworldSurfaceField(new SeededNoise(51_991));
        $previous = null;
        for ($x = -96; $x <= 96; ++$x) {
            $context = self::context(x: $x, z: 37, height: 82, slope: 1, distanceToWater: 30);
            $sample = $field->sample($context);
            self::assertEquals($sample, $field->sample($context));
            if ($previous !== null) {
                self::assertLessThan(4_500, abs($sample->rockMask - $previous));
            }
            $previous = $sample->rockMask;
        }
    }

    public function testGentleInlandGroundNeverProducesIsolatedRockOrScree(): void
    {
        $field = new OverworldSurfaceField(new SeededNoise(938_113));
        for ($z = -128; $z <= 128; $z += 4) {
            for ($x = -128; $x <= 128; $x += 4) {
                $material = $field->sample(self::context(
                    x: $x,
                    z: $z,
                    height: 75,
                    slope: 1,
                    distanceToWater: 24,
                ))->material;
                self::assertNotSame(OverworldSurfaceMaterial::Stone, $material);
                self::assertNotSame(OverworldSurfaceMaterial::GravelScree, $material);
            }
        }
    }

    public function testTerrainMeasurementsOverrideDecorativeNoiseAtHardBoundaries(): void
    {
        $field = new OverworldSurfaceField(new SeededNoise(1));

        self::assertSame(
            OverworldSurfaceMaterial::Stone,
            $field->sample(self::context(slope: 14))->material,
        );
        self::assertContains(
            $field->sample(self::context(height: 62, slope: 1, distanceToWater: 2))->material,
            [OverworldSurfaceMaterial::Sand, OverworldSurfaceMaterial::GravelShore],
        );
        self::assertContains(
            $field->sample(self::context(waterDepth: 5))->material,
            [
                OverworldSurfaceMaterial::ClayBed,
                OverworldSurfaceMaterial::MudBed,
                OverworldSurfaceMaterial::GravelShore,
            ],
        );
    }

    public function testTerrainSampleCurvatureProducesConnectedScreeOnlyOnConcaveSlopes(): void
    {
        $field = new OverworldSurfaceField(new SeededNoise(51_991));
        $climate = new OverworldClimate(0, 0, 4_000, 8_000, 0, 0, 0, 0);
        for ($x = -47; $x <= -43; ++$x) {
            $concave = new OverworldTerrainSample($climate, 80, 9, 0, -3);
            $convex = new OverworldTerrainSample($climate, 80, 9, 0, 3);
            $concaveSurface = $field->sample(self::contextFromTerrainSample($concave, $x, -128));
            $convexSurface = $field->sample(self::contextFromTerrainSample($convex, $x, -128));

            self::assertGreaterThanOrEqual(5_000, $concaveSurface->rockMask);
            self::assertSame(OverworldSurfaceMaterial::GravelScree, $concaveSurface->material);
            self::assertNotSame(OverworldSurfaceMaterial::GravelScree, $convexSurface->material);
        }
    }

    public function testModerateRollingSlopesRemainVegetatedAcrossRockMaskExtremes(): void
    {
        $field = new OverworldSurfaceField(new SeededNoise(51_991));
        for ($z = -256; $z <= 256; $z += 8) {
            for ($x = -256; $x <= 256; $x += 8) {
                $material = $field->sample(self::context(
                    x: $x,
                    z: $z,
                    height: 78,
                    slope: 6,
                    curvature: -4,
                    distanceToWater: 24,
                ))->material;
                self::assertNotSame(OverworldSurfaceMaterial::Stone, $material);
                self::assertNotSame(OverworldSurfaceMaterial::GravelScree, $material);
            }
        }
    }

    private static function context(
        int $x = 10,
        int $z = 20,
        int $height = 80,
        int $slope = 1,
        int $curvature = 0,
        int $waterDepth = 0,
        int $distanceToWater = 20,
    ): OverworldSurfaceContext {
        return new OverworldSurfaceContext(
            $x,
            $z,
            $height,
            63,
            $slope,
            $curvature,
            $waterDepth,
            $distanceToWater,
            0,
            4_000,
            8_000,
        );
    }

    private static function contextFromTerrainSample(
        OverworldTerrainSample $sample,
        int $x,
        int $z,
    ): OverworldSurfaceContext {
        return new OverworldSurfaceContext(
            $x,
            $z,
            $sample->surfaceHeight,
            63,
            $sample->slope,
            $sample->curvature,
            0,
            20,
            $sample->riverStrength,
            $sample->climate->temperature,
            $sample->climate->humidity,
        );
    }
}
