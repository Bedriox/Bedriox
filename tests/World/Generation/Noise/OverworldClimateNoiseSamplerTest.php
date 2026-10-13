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

use Bedriox\Server\World\Generation\Noise\OverworldClimateNoiseSampler;
use PHPUnit\Framework\TestCase;

final class OverworldClimateNoiseSamplerTest extends TestCase
{
    public function testSeedZeroMatchesObservedReferenceClimateCacheEntry(): void
    {
        $sample = (new OverworldClimateNoiseSampler(0))->sample(-14, -10);

        self::assertEqualsWithDelta(-13.630298614501953, $sample->shiftedX, 0.00002);
        self::assertEqualsWithDelta(-10.735118865966797, $sample->shiftedZ, 0.00002);
        self::assertEqualsWithDelta(0.05167429521679878, $sample->continentalness, 0.00002);
        self::assertEqualsWithDelta(-0.0959620252251625, $sample->weirdness, 0.00002);
        self::assertEqualsWithDelta(-0.08093611150979996, $sample->erosion, 0.00002);
        self::assertEqualsWithDelta(0.09963421523571014, $sample->temperature, 0.00002);
        self::assertEqualsWithDelta(0.03266546130180359, $sample->humidity, 0.00002);
    }
}
