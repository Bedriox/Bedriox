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
use Bedriox\Server\Gameplay\Block\Environment\GiantSpruceGrowthResolver;
use Bedriox\Server\World\BlockPosition;
use PHPUnit\Framework\TestCase;

final class GiantSpruceGrowthResolverTest extends TestCase
{
    public function testSelectsTheNorthWestCornerOfAFourSaplingCluster(): void
    {
        $spruce = CanonicalBlockState::from('minecraft:spruce_sapling', ['age_bit' => 1]);
        $origin = (new GiantSpruceGrowthResolver())->origin(new BlockPosition(10, 64, 20), [
            '0:0' => $spruce,
            '-1:0' => $spruce,
            '0:-1' => $spruce,
            '-1:-1' => $spruce,
        ]);

        self::assertNotNull($origin);
        self::assertSame([9, 64, 19], [$origin->x, $origin->y, $origin->z]);
    }

    public function testPodzolAloneDoesNotSelectGiantSpruceGrowth(): void
    {
        $podzol = CanonicalBlockState::from('minecraft:podzol');
        self::assertNull((new GiantSpruceGrowthResolver())->origin(new BlockPosition(0, 64, 0), [
            '0:0' => $podzol,
            '1:0' => $podzol,
            '0:1' => $podzol,
            '1:1' => $podzol,
        ]));
    }
}
