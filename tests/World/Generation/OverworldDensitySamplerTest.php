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

use Bedriox\Server\World\Generation\OverworldDensitySampler;
use Bedriox\Server\World\Generation\OverworldTerrainSampler;
use Bedriox\Server\World\Generation\SeededNoise;
use PHPUnit\Framework\TestCase;

final class OverworldDensitySamplerTest extends TestCase
{
    public function testAquifersAreDeterministicBoundedAndUseTheDeepLavaFloor(): void
    {
        $first = self::sampler(73_019);
        $second = self::sampler(73_019);

        for ($z = -64; $z <= 64; $z += 8) {
            for ($x = -64; $x <= 64; $x += 8) {
                self::assertSame(-54, $first->aquiferLevel($x, -55, $z));
                self::assertNull($first->aquiferLevel($x, 37, $z));
                for ($y = -48; $y <= 32; $y += 8) {
                    $level = $first->aquiferLevel($x, $y, $z);
                    self::assertSame($level, $second->aquiferLevel($x, $y, $z));
                    if ($level !== null) {
                        self::assertGreaterThanOrEqual(7, $level);
                        self::assertLessThanOrEqual(17, $level);
                    }
                }
            }
        }
    }

    private static function sampler(int $seed): OverworldDensitySampler
    {
        $noise = new SeededNoise($seed);

        return new OverworldDensitySampler($noise, new OverworldTerrainSampler($noise));
    }
}
