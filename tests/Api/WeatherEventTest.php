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

namespace Bedriox\Server\Tests\Api;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Event\World\WeatherChangeCause;
use Bedriox\Api\Event\World\WeatherChangedEvent;
use Bedriox\Api\Event\World\WeatherChangeEvent;
use Bedriox\Api\World\WeatherState;
use Bedriox\Api\World\WeatherType;
use Bedriox\Api\World\World;
use PHPUnit\Framework\TestCase;

final class WeatherEventTest extends TestCase
{
    public function testPreEventIsCancellableAndAllowsBoundedReplacement(): void
    {
        $world = new World('overworld', 1);
        $clear = new WeatherState(WeatherType::CLEAR, 12_000);
        $rain = new WeatherState(WeatherType::RAIN, 6_000);
        $thunder = new WeatherState(WeatherType::THUNDER, 1_200);
        $event = new WeatherChangeEvent($world, $clear, $rain, WeatherChangeCause::COMMAND);

        self::assertInstanceOf(CancellableEvent::class, $event);
        self::assertSame($rain, $event->weather());
        $event->setWeather($thunder);
        $event->cancel();

        self::assertSame($thunder, $event->weather());
        self::assertTrue($event->isCancelled());
        self::assertSame(WeatherChangeCause::COMMAND, $event->cause);
    }

    public function testPostEventCarriesCommittedImmutableSnapshots(): void
    {
        $world = new World('overworld', 1);
        $clear = new WeatherState(WeatherType::CLEAR, 12_000);
        $rain = new WeatherState(WeatherType::RAIN, 6_000);
        $event = new WeatherChangedEvent($world, $clear, $rain, WeatherChangeCause::NATURAL);

        self::assertInstanceOf(PostEvent::class, $event);
        self::assertSame($world, $event->world);
        self::assertSame($clear, $event->previous);
        self::assertSame($rain, $event->weather);
        self::assertSame(WeatherChangeCause::NATURAL, $event->cause);
    }
}
