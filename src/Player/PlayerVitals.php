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

/** Mutable health and nutrition state owned exclusively by the authoritative world tick. */
final class PlayerVitals
{
    public const float MAX_HEALTH = 20.0;
    public const float MAX_FOOD = 20.0;
    public const float MAX_SATURATION = 20.0;
    public const float EXHAUSTION_THRESHOLD = 4.0;

    public int $invulnerableUntilTick = -1;
    public int $foodTickTimer = 0;

    public function __construct(
        public float $health = self::MAX_HEALTH,
        public float $food = self::MAX_FOOD,
        public float $saturation = self::MAX_SATURATION,
        public float $exhaustion = 0.0,
    ) {
        self::assertRange($health, 0.0, self::MAX_HEALTH, 'health');
        self::assertRange($food, 0.0, self::MAX_FOOD, 'food');
        self::assertRange($saturation, 0.0, self::MAX_SATURATION, 'saturation');
        self::assertRange($exhaustion, 0.0, self::EXHAUSTION_THRESHOLD, 'exhaustion', false);
    }

    public function isAlive(): bool
    {
        return $this->health > 0.0;
    }

    public function canConsume(bool $requiresHunger): bool
    {
        return !$requiresHunger || $this->food < self::MAX_FOOD;
    }

    public function addNutrition(float $food, float $saturation): bool
    {
        if (!is_finite($food) || $food < 0.0 || !is_finite($saturation) || $saturation < 0.0) {
            throw new InvalidArgumentException('Nutrition additions must be finite and non-negative.');
        }
        $newFood = min(self::MAX_FOOD, $this->food + $food);
        $newSaturation = min(self::MAX_SATURATION, $this->saturation + $saturation);
        if ($newFood === $this->food && $newSaturation === $this->saturation) {
            return false;
        }
        $this->food = $newFood;
        $this->saturation = $newSaturation;

        return true;
    }

    public function setNutrition(float $food, float $saturation, float $exhaustion): void
    {
        self::assertRange($food, 0.0, self::MAX_FOOD, 'food');
        self::assertRange($saturation, 0.0, self::MAX_SATURATION, 'saturation');
        self::assertRange($exhaustion, 0.0, self::EXHAUSTION_THRESHOLD, 'exhaustion', false);
        $this->food = $food;
        $this->saturation = $saturation;
        $this->exhaustion = $exhaustion;
    }

    /** Applies vanilla exhaustion rollover and returns whether food or saturation changed. */
    public function exhaust(float $amount): bool
    {
        if (!is_finite($amount) || $amount < 0.0 || $amount > 1_000_000.0) {
            throw new InvalidArgumentException('Exhaustion must be finite, non-negative, and bounded.');
        }
        $previousExhaustion = $this->exhaustion;
        $changed = false;
        $exhaustion = $this->exhaustion + $amount;
        while ($exhaustion >= self::EXHAUSTION_THRESHOLD) {
            $exhaustion -= self::EXHAUSTION_THRESHOLD;
            if ($this->saturation > 0.0) {
                $this->saturation = max(0.0, $this->saturation - 1.0);
                $changed = true;
            } elseif ($this->food > 0.0) {
                $this->food = max(0.0, $this->food - 1.0);
                $changed = true;
            }
        }
        $this->exhaustion = $exhaustion;

        return $changed || $this->exhaustion !== $previousExhaustion;
    }

    public function resetNutrition(): void
    {
        $this->food = self::MAX_FOOD;
        $this->saturation = self::MAX_SATURATION;
        $this->exhaustion = 0.0;
        $this->foodTickTimer = 0;
    }

    private static function assertRange(
        float $value,
        float $minimum,
        float $maximum,
        string $name,
        bool $inclusiveMaximum = true,
    ): void {
        if (!is_finite($value) || $value < $minimum
            || ($inclusiveMaximum ? $value > $maximum : $value >= $maximum)) {
            throw new InvalidArgumentException("Player $name is outside its authoritative range.");
        }
    }
}
