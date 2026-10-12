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

namespace Bedriox\Server\Tests\Gameplay\Block\Environment;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Block\Environment\FarmlandHydrationSearch;
use Bedriox\Server\World\BlockPosition;
use PHPUnit\Framework\TestCase;

final class FarmlandHydrationSearchTest extends TestCase
{
    public function testSearchesSameLevelBeforeOneBlockAboveWithinFourBlocks(): void
    {
        $search = new FarmlandHydrationSearch();
        $farmland = new BlockPosition(10, 64, 10);
        $water = new BlockPosition(14, 64, 6);
        $probes = 0;
        $found = $search->findWater($farmland, static function (BlockPosition $position) use ($water, &$probes): CanonicalBlockState {
            ++$probes;
            return $position->equals($water)
                ? CanonicalBlockState::from('minecraft:water', ['liquid_depth' => 0])
                : CanonicalBlockState::from('minecraft:air');
        });

        self::assertNotNull($found);
        self::assertTrue($found->equals($water));
        self::assertLessThanOrEqual(162, $probes);
    }

    public function testChecksValidCachedWaterBeforeScanning(): void
    {
        $search = new FarmlandHydrationSearch();
        $farmland = new BlockPosition(0, 64, 0);
        $cached = new BlockPosition(2, 65, -1);
        $probes = 0;
        $found = $search->findWater(
            $farmland,
            static function (BlockPosition $position) use ($cached, &$probes): CanonicalBlockState {
                ++$probes;
                return $position->equals($cached)
                    ? CanonicalBlockState::from('minecraft:water', ['liquid_depth' => 0])
                    : CanonicalBlockState::from('minecraft:air');
            },
            $cached,
        );

        self::assertSame($cached, $found);
        self::assertSame(1, $probes);
    }

    public function testReturnsNullAfterBoundedSearchWithoutWater(): void
    {
        $probes = 0;
        $found = (new FarmlandHydrationSearch())->findWater(
            new BlockPosition(0, 64, 0),
            static function (BlockPosition $_position) use (&$probes): CanonicalBlockState {
                ++$probes;
                return CanonicalBlockState::from('minecraft:air');
            },
        );

        self::assertNull($found);
        self::assertSame(162, $probes);
    }
}
