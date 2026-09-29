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

namespace Bedriox\Server\Tests\Gameplay\Processing;

use Bedriox\Server\Gameplay\Processing\StationTickScheduler;
use PHPUnit\Framework\TestCase;

final class StationTickSchedulerTest extends TestCase
{
    public function testDrainsDueStationsInTickThenInsertionOrder(): void
    {
        $scheduler = new StationTickScheduler();
        $scheduler->schedule('world:0:64:0', 20);
        $scheduler->schedule('world:1:64:0', 10);
        $scheduler->schedule('world:2:64:0', 10);

        self::assertSame([], $scheduler->drainDue(9, 10));
        self::assertSame(
            ['world:1:64:0', 'world:2:64:0'],
            array_map(static fn($entry): string => $entry->key, $scheduler->drainDue(10, 10)),
        );
        self::assertSame(20, $scheduler->nextDueTick());
    }

    public function testRescheduleAndCancellationDiscardStaleGenerations(): void
    {
        $scheduler = new StationTickScheduler();
        $scheduler->schedule('world:0:64:0', 5);
        $scheduler->schedule('world:0:64:0', 50);
        $scheduler->schedule('world:1:64:0', 6);
        self::assertTrue($scheduler->cancel('world:1:64:0'));

        self::assertSame([], $scheduler->drainDue(49, 10));
        self::assertSame(['world:0:64:0'], array_map(
            static fn($entry): string => $entry->key,
            $scheduler->drainDue(50, 10),
        ));
        self::assertSame(0, $scheduler->count());
    }

    public function testDrainBudgetLeavesOtherDueStationsScheduled(): void
    {
        $scheduler = new StationTickScheduler();
        for ($index = 0; $index < 4; ++$index) {
            $scheduler->schedule('world:' . $index . ':64:0', 1);
        }

        self::assertCount(2, $scheduler->drainDue(1, 2));
        self::assertSame(2, $scheduler->count());
        self::assertCount(2, $scheduler->drainDue(1, 2));
    }
}
