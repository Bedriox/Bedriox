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

namespace Bedriox\Server\World\Generation\Noise;

use Closure;
use InvalidArgumentException;

final readonly class CubicSpline
{
    /**
     * @param Closure(TerrainClimatePoint): float $coordinate
     * @param non-empty-list<CubicSplinePoint> $points
     */
    public function __construct(
        private Closure $coordinate,
        private array $points,
    ) {
        if (count($points) < 2) {
            throw new InvalidArgumentException('A cubic spline requires at least two control points');
        }
    }

    public function evaluate(TerrainClimatePoint $point): float
    {
        $coordinate = ($this->coordinate)($point);
        $lastIndex = count($this->points) - 1;
        $first = $this->points[0];
        if ($coordinate <= $first->location) {
            return $first->evaluate($point) + ($first->derivative * ($coordinate - $first->location));
        }

        $last = $this->points[$lastIndex];
        if ($coordinate >= $last->location) {
            return $last->evaluate($point) + ($last->derivative * ($coordinate - $last->location));
        }

        for ($index = 0; $index < $lastIndex; ++$index) {
            $left = $this->points[$index];
            $right = $this->points[$index + 1];
            if ($coordinate > $right->location) {
                continue;
            }

            $distance = $right->location - $left->location;
            $progress = ($coordinate - $left->location) / $distance;
            $leftValue = $left->evaluate($point);
            $rightValue = $right->evaluate($point);
            $difference = $rightValue - $leftValue;
            $linear = self::lerp($progress, $leftValue, $rightValue);
            $curvature = $progress * (1.0 - $progress) * self::lerp(
                $progress,
                ($left->derivative * $distance) - $difference,
                $difference - ($right->derivative * $distance),
            );

            return $linear + $curvature;
        }

        return $last->evaluate($point);
    }

    private static function lerp(float $progress, float $from, float $to): float
    {
        return $from + ($progress * ($to - $from));
    }
}
