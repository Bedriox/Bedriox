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

namespace Bedriox\Server\Tests\World\Generation\Landform;

use Bedriox\Server\World\Generation\Landform\OverworldLandformField;
use Bedriox\Server\World\Generation\OverworldClimate;
use Bedriox\Server\World\Generation\SeededNoise;
use PHPUnit\Framework\TestCase;

final class OverworldLandformFieldTest extends TestCase
{
    public function testDryAndColdClimateDoesNotBecomeWetland(): void
    {
        $field = new OverworldLandformField(new SeededNoise(9_123));
        $dry = self::climate(temperature: 12_000, humidity: -8_000);
        $cold = self::climate(temperature: -8_000, humidity: 15_000);

        self::assertSame(72, $field->surfaceHeight(40, -20, 72, $dry, 0));
        self::assertSame(72, $field->surfaceHeight(40, -20, 72, $cold, 0));
    }

    public function testWetlandReliefIsDeterministicBoundedAndNeverRaisesDensitySurface(): void
    {
        $field = new OverworldLandformField(new SeededNoise(9_123));
        $climate = self::climate(temperature: 9_000, humidity: 17_000);
        $lowered = 0;
        for ($z = -256; $z <= 256; $z += 8) {
            for ($x = -256; $x <= 256; $x += 8) {
                $height = $field->surfaceHeight($x, $z, 72, $climate, 0);
                self::assertSame($height, $field->surfaceHeight($x, $z, 72, $climate, 0));
                self::assertLessThanOrEqual(72, $height);
                self::assertGreaterThanOrEqual(60, $height);
                $lowered += $height < 72 ? 1 : 0;
            }
        }
        self::assertGreaterThan(100, $lowered);
    }

    public function testRiverReliefRemainsContinuousAndDominatesAtFullStrength(): void
    {
        $field = new OverworldLandformField(new SeededNoise(9_123));
        $climate = self::climate(temperature: 0, humidity: 0);

        self::assertSame(72, $field->surfaceHeight(0, 0, 72, $climate, 649));
        self::assertSame(59, $field->surfaceHeight(0, 0, 72, $climate, 1_000));
        self::assertGreaterThan(59, $field->surfaceHeight(0, 0, 72, $climate, 825));
    }

    public function testOceanFloorDetailRemainsSubmergedAndBreaksPlanarTerraces(): void
    {
        $field = new OverworldLandformField(new SeededNoise(9_123));
        $climate = self::climate(temperature: 0, humidity: 0);
        $heights = [];
        for ($z = -96; $z <= 96; $z += 2) {
            for ($x = -96; $x <= 96; $x += 2) {
                $height = $field->surfaceHeight($x, $z, 54, $climate, 0);
                self::assertLessThan(OverworldLandformField::SEA_LEVEL, $height);
                $heights[$height] = true;
            }
        }
        self::assertGreaterThanOrEqual(4, count($heights));
    }

    private static function climate(int $temperature, int $humidity): OverworldClimate
    {
        return new OverworldClimate(8_000, 0, $temperature, $humidity, 0, 0, 0, 0);
    }
}
