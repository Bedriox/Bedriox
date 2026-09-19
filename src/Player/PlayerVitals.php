<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

use InvalidArgumentException;

/** Mutable health state owned exclusively by the authoritative world tick. */
final class PlayerVitals
{
    public const float MAX_HEALTH = 20.0;

    public int $invulnerableUntilTick = -1;

    public function __construct(public float $health = self::MAX_HEALTH)
    {
        if (!is_finite($health) || $health < 0.0 || $health > self::MAX_HEALTH) {
            throw new InvalidArgumentException('Player health is outside its authoritative range.');
        }
    }

    public function isAlive(): bool
    {
        return $this->health > 0.0;
    }
}
