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

namespace Bedriox\Api\Event\World;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\World\WeatherState;
use Bedriox\Api\World\World;
use InvalidArgumentException;

/** Cancellable, adjustable intent emitted before authoritative weather changes. */
final class WeatherChangeEvent extends CancellableEvent
{
    public function __construct(
        public readonly World $world,
        public readonly WeatherState $previous,
        private WeatherState $weather,
        public readonly WeatherChangeCause $cause,
    ) {}

    public function weather(): WeatherState
    {
        return $this->weather;
    }

    public function setWeather(WeatherState $weather): void
    {
        $this->assertMutable();
        $this->weather = $weather;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->weather];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !$state[1] instanceof WeatherState) {
            throw new InvalidArgumentException('Invalid weather-change event state.');
        }
        parent::replaceState($state[0]);
        $this->weather = $state[1];
    }
}
