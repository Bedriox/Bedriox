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

namespace Bedriox\Server\Tests\World\Generation;

use Bedriox\Server\World\Generation\OverworldBiomeResolver;
use Bedriox\Server\World\Generation\OverworldTerrainSampler;
use Bedriox\Server\World\Generation\SeededNoise;
use PHPUnit\Framework\TestCase;

final class OverworldTerrainSamplerTest extends TestCase
{
    public function testRegionalProfileContainsOceansLowlandsHighlandsAndClimateBiomes(): void
    {
        $terrain = new OverworldTerrainSampler(new SeededNoise(0));
        $resolver = new OverworldBiomeResolver();
        $minimum = PHP_INT_MAX;
        $maximum = PHP_INT_MIN;
        $biomes = [];
        for ($z = -2_048; $z <= 2_048; $z += 128) {
            for ($x = -2_048; $x <= 2_048; $x += 128) {
                $sample = $terrain->sample($x, $z);
                $minimum = min($minimum, $sample->surfaceHeight);
                $maximum = max($maximum, $sample->surfaceHeight);
                $biomes[$resolver->resolve($sample)->identifier] = true;
            }
        }

        self::assertLessThanOrEqual(55, $minimum);
        self::assertGreaterThanOrEqual(110, $maximum);
        self::assertGreaterThanOrEqual(8, count($biomes));
        self::assertArrayHasKey('minecraft:ocean', $biomes);
        self::assertArrayHasKey('minecraft:plains', $biomes);
        self::assertArrayHasKey('minecraft:forest', $biomes);
        self::assertArrayHasKey('minecraft:desert', $biomes);
        self::assertTrue(isset($biomes['minecraft:extreme_hills']) || isset($biomes['minecraft:snowy_slopes']));
    }

    public function testUnitScaleTerrainHasNoColumnCliffs(): void
    {
        $terrain = new OverworldTerrainSampler(new SeededNoise(48_151));
        for ($z = -64; $z < 64; ++$z) {
            for ($x = -64; $x < 64; ++$x) {
                $height = $terrain->heightAt($x, $z);
                self::assertLessThanOrEqual(4, abs($height - $terrain->heightAt($x + 1, $z)));
                self::assertLessThanOrEqual(4, abs($height - $terrain->heightAt($x, $z + 1)));
            }
        }
    }
}
