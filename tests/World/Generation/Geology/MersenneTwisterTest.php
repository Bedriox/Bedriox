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
use PHPUnit\Framework\TestCase;

final class MersenneTwisterTest extends TestCase
{
    public function testMatchesCapturedUnsignedIntegerAndFloatStream(): void
    {
        $random = new MersenneTwister(3_618_927_284);

        self::assertSame(3_092_163_822, $random->nextUint32());
        self::assertSame(48, $random->nextInt(136));
        self::assertSame(6, $random->nextInt(16));

        $placement = new MersenneTwister(4_110_862_905);
        self::assertSame(867_040_317, $placement->nextUint32());
        $placement = new MersenneTwister(4_110_862_905);
        self::assertSame(0.2018735557794571, $placement->nextFloat());
    }
}
