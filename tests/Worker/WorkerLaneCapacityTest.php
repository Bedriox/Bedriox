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

namespace Bedriox\Server\Tests\Worker;

use Bedriox\Server\Worker\WorkerLane;
use Bedriox\Server\Worker\WorkerLaneCapacity;
use PHPUnit\Framework\TestCase;

final class WorkerLaneCapacityTest extends TestCase
{
    public function testAutomaticPoolReservesTwoWorkersFromBulkWorldWork(): void
    {
        $capacity = new WorkerLaneCapacity(8);

        self::assertSame(6, $capacity->maximumWorldWorkers());
        self::assertTrue($capacity->canDispatch(WorkerLane::WORLD, 5));
        self::assertFalse($capacity->canDispatch(WorkerLane::WORLD, 6));
        self::assertTrue($capacity->canDispatch(WorkerLane::NETWORK, 6));
        self::assertTrue($capacity->canDispatch(WorkerLane::AUTHENTICATION, 7));
    }

    public function testSmallPoolsRetainWorldProgress(): void
    {
        self::assertSame(1, (new WorkerLaneCapacity(1))->maximumWorldWorkers());
        self::assertSame(1, (new WorkerLaneCapacity(2))->maximumWorldWorkers());
        self::assertSame(3, (new WorkerLaneCapacity(4))->maximumWorldWorkers());
    }
}
