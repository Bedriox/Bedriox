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

use Bedriox\Server\World\Generation\Noise\BlendedTerrainNoise;
use Bedriox\Server\World\Generation\Noise\Xoroshiro128PlusPlus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlendedTerrainNoiseTest extends TestCase
{
    #[DataProvider('samples')]
    public function testSeededFieldIsDeterministic(
        int $x,
        int $y,
        int $z,
        float $expected,
    ): void {
        $noise = new BlendedTerrainNoise(Xoroshiro128PlusPlus::fromSeed(0));

        self::assertEqualsWithDelta($expected, $noise->sample($x, $y, $z), 1.0e-12);
    }

    /** @return iterable<string, array{int, int, int, float}> */
    public static function samples(): iterable
    {
        yield 'origin' => [0, 0, 0, 0.052837270865629352];
        yield 'below sea level' => [4, -64, 8, -0.18854889009901338];
        yield 'negative chunk' => [-48, 64, -32, -0.20939707946346722];
        yield 'distant coordinate' => [1024, 96, -2048, 0.15793608500996514];
    }
}
