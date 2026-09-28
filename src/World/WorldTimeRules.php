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

namespace Bedriox\Server\World;

use InvalidArgumentException;

/** Bounded arithmetic shared by world ticking, commands, persistence, and protocol projection. */
final class WorldTimeRules
{
    public const int TICKS_PER_DAY = 24_000;
    public const int MINIMUM = 0;
    public const int MAXIMUM = 0x7fffffff;
    public const int SYNCHRONIZATION_INTERVAL_TICKS = 200;

    public static function validate(int $time): int
    {
        if ($time < self::MINIMUM || $time > self::MAXIMUM) {
            throw new InvalidArgumentException('World time must be between 0 and 2147483647 ticks.');
        }

        return $time;
    }

    public static function add(int $time, int $amount): int
    {
        self::validate($time);
        self::validate($amount);
        if ($amount <= self::MAXIMUM - $time) {
            return $time + $amount;
        }

        return (self::timeOfDay($time) + ($amount % self::TICKS_PER_DAY)) % self::TICKS_PER_DAY;
    }

    public static function timeOfDay(int $time): int
    {
        return self::validate($time) % self::TICKS_PER_DAY;
    }

    public static function day(int $time): int
    {
        return intdiv(self::validate($time), self::TICKS_PER_DAY);
    }
}
