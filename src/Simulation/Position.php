<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

final readonly class Position
{
    public function __construct(
        public float $x,
        public float $y,
        public float $z,
    ) {}

    public function distanceTo(self $other): float
    {
        $horizontal = hypot($this->x - $other->x, $this->z - $other->z);

        return hypot($horizontal, $this->y - $other->y);
    }
}
