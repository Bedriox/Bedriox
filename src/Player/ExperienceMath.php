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

namespace Bedriox\Server\Player;

use InvalidArgumentException;

/** Exact vanilla experience curve with bounded integer inputs. */
final class ExperienceMath
{
    public const int MAXIMUM_TOTAL_POINTS = 2_147_483_647;
    public const int MAXIMUM_LEVEL = 21_863;

    public static function totalPointsToReachLevel(int $level): int
    {
        if ($level < 0 || $level > self::MAXIMUM_LEVEL) {
            throw new InvalidArgumentException('Experience level is outside the supported range.');
        }
        $points = match (true) {
            $level <= 16 => $level ** 2 + 6 * $level,
            $level <= 31 => (int) floor(2.5 * ($level ** 2) - 40.5 * $level + 360),
            default => (int) floor(4.5 * ($level ** 2) - 162.5 * $level + 2220),
        };

        return $points;
    }

    public static function pointsToCompleteLevel(int $level): int
    {
        if ($level < 0 || $level > self::MAXIMUM_LEVEL) {
            throw new InvalidArgumentException('Experience level is outside the supported range.');
        }
        return match (true) {
            $level <= 15 => 2 * $level + 7,
            $level <= 30 => 5 * $level - 38,
            default => 9 * $level - 158,
        };
    }

    public static function levelFromTotalPoints(int $points): int
    {
        if ($points < 0 || $points > self::MAXIMUM_TOTAL_POINTS) {
            throw new InvalidArgumentException('Experience points are outside the supported range.');
        }
        $low = 0;
        $high = self::MAXIMUM_LEVEL;
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            if (self::totalPointsToReachLevel($middle) <= $points) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }
        return $low;
    }

    public static function progressFromTotalPoints(int $points, ?int $level = null): float
    {
        $level ??= self::levelFromTotalPoints($points);
        $remainder = $points - self::totalPointsToReachLevel($level);

        return min(1.0, max(0.0, $remainder / self::pointsToCompleteLevel($level)));
    }
}
