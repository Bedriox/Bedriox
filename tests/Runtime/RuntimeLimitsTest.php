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

use Bedriox\Server\Runtime\RuntimeLimits;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RuntimeLimitsTest extends TestCase
{
    public function testDefaultTransportPollBudgetBoundsBurstWork(): void
    {
        self::assertSame(512, new RuntimeLimits()->maximumDatagramsPerPoll);
    }

    public function testLargeSpawnRadiusUsesStreamingCapacityInsteadOfWholeViewCapacity(): void
    {
        $limits = new RuntimeLimits(
            maximumOutgoingPayloadsPerSession: 72,
            maximumChunkRadius: 32,
            preloadedChunkRadius: 32,
            maximumStreamingPacketsPerPoll: 64,
        );

        self::assertSame(32, $limits->preloadedChunkRadius);
    }

    public function testOutgoingCapacityMustCoverOneStreamingBatchAndHeadroom(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RuntimeLimits(
            maximumOutgoingPayloadsPerSession: 71,
            maximumStreamingPacketsPerPoll: 64,
        );
    }
}
