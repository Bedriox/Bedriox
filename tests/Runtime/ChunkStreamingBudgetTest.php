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

use Bedriox\Server\Runtime\ChunkStreamingBudget;
use PHPUnit\Framework\TestCase;

final class ChunkStreamingBudgetTest extends TestCase
{
    public function testEachBudgetIsSharedAndBounded(): void
    {
        $budget = new ChunkStreamingBudget(2, 1, 2);
        self::assertTrue($budget->hasCapacity());

        self::assertTrue($budget->claimGeneration());
        self::assertTrue($budget->claimGeneration());
        self::assertFalse($budget->claimGeneration());

        self::assertTrue($budget->claimPreparation());
        self::assertFalse($budget->claimPreparation());

        self::assertTrue($budget->hasDeliveryCapacity());
        self::assertTrue($budget->claimDelivery());
        self::assertTrue($budget->hasDeliveryCapacity());
        self::assertTrue($budget->claimDelivery());
        self::assertFalse($budget->hasDeliveryCapacity());
        self::assertFalse($budget->claimDelivery());
        self::assertFalse($budget->hasCapacity());
    }

    public function testRejectsNegativeBudgets(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ChunkStreamingBudget(-1, 0, 0);
    }

    public function testSessionSlicesRemainLocallyFairAndConsumeTheSharedBudget(): void
    {
        $shared = new ChunkStreamingBudget(2, 2, 2);
        $first = $shared->slice(1, 1, 1);
        $second = $shared->slice(1, 1, 1);
        $third = $shared->slice(1, 1, 1);

        self::assertTrue($first->claimGeneration());
        self::assertFalse($first->claimGeneration());
        self::assertTrue($second->claimGeneration());
        self::assertFalse($third->claimGeneration());

        self::assertTrue($first->claimPreparation());
        self::assertTrue($second->claimPreparation());
        self::assertFalse($third->claimPreparation());

        self::assertTrue($first->claimDelivery());
        self::assertTrue($second->claimDelivery());
        self::assertFalse($third->hasDeliveryCapacity());
        self::assertFalse($shared->hasCapacity());
    }

    public function testExpiredDeadlineStopsEveryKindOfWorkIncludingSlices(): void
    {
        $budget = new ChunkStreamingBudget(1, 1, 1, deadlineNanoseconds: hrtime(true) - 1);
        $slice = $budget->slice(1, 1, 1);

        self::assertFalse($budget->hasCapacity());
        self::assertFalse($budget->claimGeneration());
        self::assertFalse($slice->claimPreparation());
        self::assertFalse($slice->hasDeliveryCapacity());
        self::assertFalse($slice->claimDelivery());
    }
}
