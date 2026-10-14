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

use Bedriox\Server\World\Generation\Noise\OverworldCavifier;
use PHPUnit\Framework\TestCase;

final class OverworldCavifierTest extends TestCase
{
    public function testSurfaceAwareCavesNeverAlterOpenAirAboveTerrain(): void
    {
        $cavifier = new OverworldCavifier(4242);

        self::assertSame(-0.25, $cavifier->cavifyForSurface(10, 81, -30, -0.25, 72));
        self::assertSame(1.0, $cavifier->cavifyForSurface(10, 81, -30, 1.0, 72));
    }

    public function testRareEntranceFieldCanCrossTheSurfaceProtection(): void
    {
        $cavifier = new OverworldCavifier(4242);

        self::assertLessThan(0.0, $cavifier->cavifyForSurface(168, 63, 120, 0.073501831646205, 63));
    }

    public function testSurfaceAwareCompositionIsDeterministic(): void
    {
        $first = new OverworldCavifier(987654321);
        $second = new OverworldCavifier(987654321);

        self::assertSame(
            $first->cavifyForSurface(-117, 18, 203, 0.8, 74),
            $second->cavifyForSurface(-117, 18, 203, 0.8, 74),
        );
    }

    public function testBatchedColumnMatchesIndividualCavification(): void
    {
        $cavifier = new OverworldCavifier(1234);
        $base = [1.0, 0.8, -0.1, 1.2];
        $column = $cavifier->cavifyColumn(12, -20, 40, 4, $base, 64);

        foreach ($column as $offset => $density) {
            self::assertSame(
                $cavifier->cavifyForSurface(12, 40 + $offset * 4, -20, $base[$offset], 64),
                $density,
            );
        }
        self::assertSame($column, $cavifier->cavifyColumn(12, -20, 40, 4, $base, 64));
    }
}
