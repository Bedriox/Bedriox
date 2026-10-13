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

namespace Bedriox\Server\Tests\World\Generation\Geology;

use Bedriox\Server\World\Generation\Geology\MersenneTwister;
use Bedriox\Server\World\Generation\Geology\OverworldFeatureSeed;
use Bedriox\Server\World\Generation\Geology\OverworldGeologyCatalog;
use PHPUnit\Framework\TestCase;

final class OverworldFeatureSeedTest extends TestCase
{
    public function testDecorationSeedsMatchThreeCapturedWorldSeeds(): void
    {
        self::assertSame(189_112_192, OverworldFeatureSeed::decoration(0, -2, 2));
        self::assertSame(2_491_780_293, OverworldFeatureSeed::decoration(1, -2, 2));
        self::assertSame(3_688_261_683, OverworldFeatureSeed::decoration(-123_456_789, -2, 2));
    }

    public function testIdentifierHashCombineAndOriginMatchCapturedRule(): void
    {
        $identifier = 'minecraft:overworld_underground_iron_ore_small_feature';
        self::assertSame(1_786_850_203, OverworldFeatureSeed::identifierHashLow($identifier));
        self::assertSame(3_618_927_284, OverworldFeatureSeed::feature(189_112_192, $identifier));

        $rule = OverworldGeologyCatalog::rules()[0];
        $random = new MersenneTwister(3_618_927_284);
        self::assertSame(10, $rule->attemptCount($random));
        self::assertSame(['x' => -26, 'y' => -16, 'z' => 46], $rule->origin($random, -2, 2));
    }
}
