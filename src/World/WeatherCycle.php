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

use Bedriox\Api\World\WeatherState;
use Bedriox\Api\World\WeatherType;
use InvalidArgumentException;
use OverflowException;

/** Pure deterministic natural-weather progression; callers own events and authoritative mutation. */
final class WeatherCycle
{
    private const int MINIMUM_CLEAR_TICKS = 12_000;
    private const int MAXIMUM_CLEAR_TICKS = 168_000;
    private const int MINIMUM_RAIN_TICKS = 12_000;
    private const int MAXIMUM_RAIN_TICKS = 24_000;
    private const int MINIMUM_THUNDER_TICKS = 3_600;
    private const int MAXIMUM_THUNDER_TICKS = 15_600;
    private const int THUNDER_CHANCE_DENOMINATOR = 5;
    private const int MAXIMUM_ADVANCE_TICKS = WeatherState::MAXIMUM_DURATION_TICKS;

    public static function initial(int $worldSeed): WeatherCycleState
    {
        return new WeatherCycleState(
            new WeatherState(WeatherType::CLEAR, self::duration(WeatherType::CLEAR, $worldSeed, 0)),
        );
    }

    /**
     * Advances without reading wall-clock time or mutable global randomness.
     *
     * Disabling the weather cycle freezes both the visible state and its timer.
     */
    public static function advance(
        WeatherCycleState $state,
        int $elapsedTicks,
        bool $cycleEnabled,
        int $worldSeed,
    ): WeatherCycleState {
        if ($elapsedTicks < 0 || $elapsedTicks > self::MAXIMUM_ADVANCE_TICKS) {
            throw new InvalidArgumentException('Weather advance must be between 0 and 20000000 ticks.');
        }
        if (!$cycleEnabled || $elapsedTicks === 0) {
            return $state;
        }

        $weather = $state->weather;
        $sequence = $state->transitionSequence;
        while ($elapsedTicks >= $weather->remainingTicks) {
            if ($weather->remainingTicks > 0) {
                $elapsedTicks -= $weather->remainingTicks;
            }
            if ($sequence === 0x7fffffff) {
                throw new OverflowException('Weather transition sequence is exhausted.');
            }
            ++$sequence;
            $type = self::nextType($weather->type, $worldSeed, $sequence);
            $weather = new WeatherState($type, self::duration($type, $worldSeed, $sequence));
        }

        return new WeatherCycleState($weather->withRemainingTicks($weather->remainingTicks - $elapsedTicks), $sequence);
    }

    private static function nextType(WeatherType $current, int $worldSeed, int $sequence): WeatherType
    {
        if ($current !== WeatherType::CLEAR) {
            return WeatherType::CLEAR;
        }

        return self::sample($worldSeed, $sequence, 'type', self::THUNDER_CHANCE_DENOMINATOR) === 0
            ? WeatherType::THUNDER
            : WeatherType::RAIN;
    }

    private static function duration(WeatherType $type, int $worldSeed, int $sequence): int
    {
        [$minimum, $maximum] = match ($type) {
            WeatherType::CLEAR => [self::MINIMUM_CLEAR_TICKS, self::MAXIMUM_CLEAR_TICKS],
            WeatherType::RAIN => [self::MINIMUM_RAIN_TICKS, self::MAXIMUM_RAIN_TICKS],
            WeatherType::THUNDER => [self::MINIMUM_THUNDER_TICKS, self::MAXIMUM_THUNDER_TICKS],
        };

        return $minimum + self::sample($worldSeed, $sequence, 'duration:' . $type->value, $maximum - $minimum + 1);
    }

    private static function sample(int $worldSeed, int $sequence, string $purpose, int $range): int
    {
        $bytes = hash('sha256', $worldSeed . ':' . $sequence . ':' . $purpose, true);
        $sample = unpack('Vvalue', substr($bytes, 0, 4));
        if (!is_array($sample) || !isset($sample['value']) || !is_int($sample['value'])) {
            throw new InvalidArgumentException('Unable to derive deterministic weather entropy.');
        }

        return $sample['value'] % $range;
    }
}
