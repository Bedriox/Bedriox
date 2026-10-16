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

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Server\Runtime\ChunkStreamingGovernor;
use PHPUnit\Framework\TestCase;

final class ChunkStreamingGovernorTest extends TestCase
{
    public function testPressurePreservesDeliveryWhileStoppingOptionalGeneration(): void
    {
        $governor = new ChunkStreamingGovernor();
        $governor->observeTickPoll(101_000_000);

        self::assertSame(3, $governor->level());
        self::assertSame(1, $governor->generationLimit(8));
        self::assertSame(2, $governor->preparationLimit(32));
        self::assertSame(4, $governor->deliveryLimit(32));
        self::assertFalse($governor->allowsPrefetch());
    }

    public function testPressureRecoversGraduallyAfterSustainedHealthyTicks(): void
    {
        $governor = new ChunkStreamingGovernor();
        $governor->observeTickPoll(101_000_000);
        for ($tick = 0; $tick < 19; ++$tick) {
            $governor->observeTickPoll(10_000_000);
        }
        self::assertSame(3, $governor->level());
        $governor->observeTickPoll(10_000_000);

        self::assertSame(2, $governor->level());
        self::assertSame(1, $governor->generationLimit(8));
    }
}
