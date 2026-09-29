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

namespace Bedriox\Server\Tests\World;

use Bedriox\Api\World\WeatherState;
use Bedriox\Api\World\WeatherType;
use Bedriox\Server\World\WeatherCycle;
use Bedriox\Server\World\WeatherCycleState;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WeatherCycleTest extends TestCase
{
    public function testStateProjectsCanonicalBedrockLevelsAndCommandSeconds(): void
    {
        $clear = WeatherState::fromCommandDuration(WeatherType::CLEAR, 30);
        $rain = new WeatherState(WeatherType::RAIN, 100);
        $thunder = new WeatherState(WeatherType::THUNDER, 100);

        self::assertSame(600, $clear->remainingTicks);
        self::assertSame(0.0, $clear->rainLevel());
        self::assertSame(0.0, $clear->lightningLevel());
        self::assertSame(1.0, $rain->rainLevel());
        self::assertSame(0.0, $rain->lightningLevel());
        self::assertSame(1.0, $thunder->rainLevel());
        self::assertSame(1.0, $thunder->lightningLevel());
    }

    public function testCommandDurationUsesCurrentBedrockBounds(): void
    {
        self::assertSame(
            20_000_000,
            WeatherState::fromCommandDuration(WeatherType::RAIN, 1_000_000)->remainingTicks,
        );

        $this->expectException(InvalidArgumentException::class);
        WeatherState::fromCommandDuration(WeatherType::RAIN, 0);
    }

    public function testCountdownDoesNotTransitionBeforeItsDeadline(): void
    {
        $state = new WeatherCycleState(new WeatherState(WeatherType::RAIN, 20), 7);
        $advanced = WeatherCycle::advance($state, 19, true, 1234);

        self::assertSame(WeatherType::RAIN, $advanced->weather->type);
        self::assertSame(1, $advanced->weather->remainingTicks);
        self::assertSame(7, $advanced->transitionSequence);
    }

    public function testExpiredWeatherTransitionsDeterministicallyWithinBoundedDurations(): void
    {
        $state = new WeatherCycleState(new WeatherState(WeatherType::RAIN, 1), 7);

        $first = WeatherCycle::advance($state, 1, true, -91_337);
        $repeat = WeatherCycle::advance($state, 1, true, -91_337);

        self::assertEquals($first, $repeat);
        self::assertSame(WeatherType::CLEAR, $first->weather->type);
        self::assertGreaterThanOrEqual(12_000, $first->weather->remainingTicks);
        self::assertLessThanOrEqual(168_000, $first->weather->remainingTicks);
        self::assertSame(8, $first->transitionSequence);
    }

    public function testClearWeatherTransitionsOnlyToRainOrThunder(): void
    {
        $state = new WeatherCycleState(new WeatherState(WeatherType::CLEAR, 0));
        $advanced = WeatherCycle::advance($state, 0, true, 42);
        self::assertSame($state, $advanced, 'Zero elapsed ticks must be a strict no-op.');

        $advanced = WeatherCycle::advance($state, 1, true, 42);
        self::assertContains($advanced->weather->type, [WeatherType::RAIN, WeatherType::THUNDER]);
        if ($advanced->weather->type === WeatherType::RAIN) {
            self::assertGreaterThanOrEqual(12_000, $advanced->weather->remainingTicks);
            self::assertLessThanOrEqual(24_000, $advanced->weather->remainingTicks);
        } else {
            self::assertGreaterThanOrEqual(3_600, $advanced->weather->remainingTicks);
            self::assertLessThanOrEqual(15_600, $advanced->weather->remainingTicks);
        }
    }

    public function testDisabledCycleFreezesStateAndTimer(): void
    {
        $state = WeatherCycle::initial(1234);

        self::assertSame($state, WeatherCycle::advance($state, 10_000, false, 1234));
    }

    public function testLargeAdvanceCanCrossSeveralDeterministicTransitions(): void
    {
        $state = new WeatherCycleState(new WeatherState(WeatherType::THUNDER, 1), 100);
        $advanced = WeatherCycle::advance($state, 500_000, true, 9988);

        self::assertGreaterThan(100, $advanced->transitionSequence);
        self::assertGreaterThan(0, $advanced->weather->remainingTicks);
    }
}
