<?php

declare(strict_types=1);

namespace Bedriox\Api\Player;

use InvalidArgumentException;

/** Immutable bounded snapshot of a player's authoritative nutrition state. */
final readonly class Nutrition
{
    public const int MAX_FOOD_LEVEL = 20;
    public const float MAX_SATURATION_LEVEL = 20.0;
    public const float MAX_EXHAUSTION_LEVEL = 4.0;

    public function __construct(
        public int $foodLevel,
        public float $saturationLevel,
        public float $exhaustionLevel,
    ) {
        if ($foodLevel < 0 || $foodLevel > self::MAX_FOOD_LEVEL) {
            throw new InvalidArgumentException('Food level must be between 0 and 20.');
        }
        if (!is_finite($saturationLevel) || $saturationLevel < 0.0 || $saturationLevel > self::MAX_SATURATION_LEVEL) {
            throw new InvalidArgumentException('Saturation level must be finite and between 0 and 20.');
        }
        if (!is_finite($exhaustionLevel) || $exhaustionLevel < 0.0 || $exhaustionLevel >= self::MAX_EXHAUSTION_LEVEL) {
            throw new InvalidArgumentException('Exhaustion level must be finite and between 0 inclusive and 4 exclusive.');
        }
    }
}
