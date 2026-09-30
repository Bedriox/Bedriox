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

namespace Bedriox\Server\Gameplay\Combat;

use InvalidArgumentException;

/** Resolves one authoritative impulse without repeatedly damping existing motion. */
final class KnockbackResolver
{
    private const float MINIMUM_DIRECTION_LENGTH_SQUARED = 0.000_000_000_1;
    private const float MAXIMUM_STRENGTH = 16.0;

    public function resolve(
        KnockbackMotion $current,
        float $directionX,
        float $directionZ,
        float $horizontalStrength,
        float $verticalStrength,
        float $resistance,
        bool $grounded,
        float $verticalLimit,
    ): KnockbackMotion {
        self::validateFinite($directionX, 'direction X');
        self::validateFinite($directionZ, 'direction Z');
        self::validateStrength($horizontalStrength, 'horizontal strength');
        self::validateStrength($verticalStrength, 'vertical strength');
        self::validateStrength($verticalLimit, 'vertical limit');
        if (!is_finite($resistance) || $resistance < 0.0 || $resistance > 1.0) {
            throw new InvalidArgumentException('Knockback resistance must be between zero and one.');
        }

        $lengthSquared = ($directionX * $directionX) + ($directionZ * $directionZ);
        if ($lengthSquared <= self::MINIMUM_DIRECTION_LENGTH_SQUARED || $resistance >= 1.0) {
            return $current;
        }

        $inverseLength = 1.0 / sqrt($lengthSquared);
        $resistanceMultiplier = 1.0 - $resistance;
        $horizontal = $horizontalStrength * $resistanceMultiplier;
        $vertical = $verticalStrength * $resistanceMultiplier;

        return new KnockbackMotion(
            ($current->x / 2.0) + ($directionX * $inverseLength * $horizontal),
            $grounded ? min($verticalLimit, ($current->y / 2.0) + $vertical) : $current->y,
            ($current->z / 2.0) + ($directionZ * $inverseLength * $horizontal),
        );
    }

    private static function validateFinite(float $value, string $field): void
    {
        if (!is_finite($value)) {
            throw new InvalidArgumentException("Knockback {$field} must be finite.");
        }
    }

    private static function validateStrength(float $value, string $field): void
    {
        if (!is_finite($value) || $value < 0.0 || $value > self::MAXIMUM_STRENGTH) {
            throw new InvalidArgumentException("Knockback {$field} is outside its supported range.");
        }
    }
}
