<?php

declare(strict_types=1);

namespace Bedriox\Api\World;

final readonly class Position
{
    public function __construct(
        public float $x,
        public float $y,
        public float $z,
    ) {}
}
