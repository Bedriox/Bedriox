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

use Bedriox\Server\Worker\WorkerAllocation;
use PHPUnit\Framework\TestCase;

final class WorkerAllocationTest extends TestCase
{
    public function testAutoAllocationReservesCapacityForTheServerAndHost(): void
    {
        self::assertSame([1, 1], self::counts(WorkerAllocation::resolve('auto', 'auto', 4)));
        self::assertSame([4, 2], self::counts(WorkerAllocation::resolve('auto', 'auto', 8)));
        self::assertSame([6, 3], self::counts(WorkerAllocation::resolve('auto', 'auto', 12)));
        self::assertSame([8, 4], self::counts(WorkerAllocation::resolve('auto', 'auto', 16)));
    }

    public function testManualPoolDoesNotConsumeTheOtherPoolsAutoBudget(): void
    {
        self::assertSame([3, 3], self::counts(WorkerAllocation::resolve('auto', '3', 8)));
        self::assertSame([5, 1], self::counts(WorkerAllocation::resolve('5', 'auto', 8)));
        self::assertSame([0, 0], self::counts(WorkerAllocation::resolve('0', '0', 8)));
    }

    /** @return array{int, int} */
    private static function counts(WorkerAllocation $allocation): array
    {
        return [$allocation->coreWorkers, $allocation->pluginWorkers];
    }
}
