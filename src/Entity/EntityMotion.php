<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity;

use InvalidArgumentException;

final readonly class EntityMotion
{
    public function __construct(
        public float $x = 0.0,
        public float $y = 0.0,
        public float $z = 0.0,
    ) {
        if (!is_finite($x) || !is_finite($y) || !is_finite($z)
            || abs($x) > 100.0 || abs($y) > 100.0 || abs($z) > 100.0) {
            throw new InvalidArgumentException('Entity motion must be finite and bounded.');
        }
    }

    public function lengthSquared(): float
    {
        return ($this->x * $this->x) + ($this->y * $this->y) + ($this->z * $this->z);
    }
}
