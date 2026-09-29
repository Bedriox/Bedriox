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

namespace Bedriox\Server\Tests\World\Environment;

use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Environment\EnvironmentTickScheduler;
use Bedriox\Server\World\Environment\EnvironmentTickType;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class EnvironmentTickSchedulerTest extends TestCase
{
    public function testDueOrderingIsStableAndCoordinatesAreDeduplicatedByType(): void
    {
        $scheduler = new EnvironmentTickScheduler(8, static fn(): int => 0);
        $first = new BlockPosition(1, 64, 1);
        $second = new BlockPosition(2, 64, 1);
        $later = new BlockPosition(3, 64, 1);

        self::assertTrue($scheduler->schedule($later, EnvironmentTickType::FLUID, 10, 5));
        self::assertTrue($scheduler->schedule($first, EnvironmentTickType::FLUID, 10, 2));
        self::assertTrue($scheduler->schedule($second, EnvironmentTickType::FLUID, 10, 2));
        self::assertTrue($scheduler->schedule($first, EnvironmentTickType::FLUID, 10, 0));
        self::assertFalse($scheduler->schedule($first, EnvironmentTickType::FLUID, 10, 1));
        self::assertTrue($scheduler->schedule($first, EnvironmentTickType::FIRE, 10, 2));

        $drain = $scheduler->drain(12, 8, 1_000);

        self::assertSame([$first, $second, $first], array_map(
            static fn($tick): BlockPosition => $tick->position,
            $drain->ticks,
        ));
        self::assertSame([
            EnvironmentTickType::FLUID,
            EnvironmentTickType::FLUID,
            EnvironmentTickType::FIRE,
        ], array_map(static fn($tick): EnvironmentTickType => $tick->type, $drain->ticks));
        self::assertSame(1, $drain->queuedAfterDrain);
        self::assertFalse($drain->budgetExhausted);
    }

    public function testCountAndTimeBudgetsLeaveDueWorkQueued(): void
    {
        $now = 0;
        $scheduler = new EnvironmentTickScheduler(8, static function () use (&$now): int {
            $now += 2_000;

            return $now;
        });
        for ($x = 0; $x < 3; ++$x) {
            $scheduler->schedule(new BlockPosition($x, 64, 0), EnvironmentTickType::FLUID, 0, 0);
        }

        $drain = $scheduler->drain(0, 3, 1);

        self::assertCount(1, $drain->ticks);
        self::assertSame(2, $drain->queuedAfterDrain);
        self::assertTrue($drain->budgetExhausted);
    }

    public function testCancellationIsLazyAndCapacityIsBounded(): void
    {
        $scheduler = new EnvironmentTickScheduler(1, static fn(): int => 0);
        $position = new BlockPosition(0, 64, 0);
        self::assertTrue($scheduler->schedule($position, EnvironmentTickType::FLUID, 0, 0));
        self::assertTrue($scheduler->cancel($position, EnvironmentTickType::FLUID));
        self::assertSame([], $scheduler->drain(0, 1, 1_000)->ticks);

        self::assertTrue($scheduler->schedule($position, EnvironmentTickType::FLUID, 0, 0));
        $this->expectException(OverflowException::class);
        $scheduler->schedule(new BlockPosition(1, 64, 0), EnvironmentTickType::FLUID, 0, 0);
    }

    public function testCancelledEntryCannotConsumeAReplacementAtTheSamePosition(): void
    {
        $scheduler = new EnvironmentTickScheduler(2, static fn(): int => 0);
        $position = new BlockPosition(0, 64, 0);
        self::assertTrue($scheduler->schedule($position, EnvironmentTickType::FLUID, 0, 10));
        self::assertTrue($scheduler->cancel($position, EnvironmentTickType::FLUID));
        self::assertTrue($scheduler->schedule($position, EnvironmentTickType::FLUID, 0, 20));

        self::assertSame([], $scheduler->drain(10, 2, 1_000)->ticks);
        $replacement = $scheduler->drain(20, 2, 1_000)->ticks;
        self::assertCount(1, $replacement);
        self::assertSame(20, $replacement[0]->dueTick);
    }
}
